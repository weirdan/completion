<?php

namespace Phpactor\Completion\Bridge\TolerantParser\WorseReflection;

use LogicException;
use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\QualifiedName;
use Phpactor\Completion\Bridge\TolerantParser\TolerantCompletor;
use Phpactor\Completion\Core\Formatter\ObjectFormatter;
use Phpactor\Completion\Core\Response;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\Completion\Core\Suggestions;
use Phpactor\WorseReflection\Core\Exception\CouldNotResolveNode;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Inference\Variable as WorseVariable;
use Phpactor\WorseReflection\Core\Reflection\ReflectionFunctionLike;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMethod;
use Phpactor\WorseReflection\Core\Reflection\ReflectionParameter;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Reflector;

class WorseParameterCompletor extends AbstractVariableCompletor implements TolerantCompletor
{
    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var ObjectFormatter
     */
    private $formatter;

    public function __construct(Reflector $reflector, ObjectFormatter $formatter)
    {
        parent::__construct($reflector);
        $this->reflector = $reflector;
        $this->formatter = $formatter;
    }

    public function complete(Node $node, string $source, int $offset): Response
    {
        $response = Response::new();

        if (!$node instanceof Variable && !$node instanceof CallExpression) {
            return $response;
        }

        $callExpression = $node instanceof CallExpression ? $node : $node->getFirstAncestor(CallExpression::class);

        if (!$callExpression) {
            return $response;
        }

        assert($callExpression instanceof CallExpression);
        $callableExpression = $callExpression->callableExpression;

        $variables = $this->variableCompletions($node, $source, $offset);

        if (empty($variables)) {
            $response->issues()->add('No variables available');
            return $response;
        }

        try {
            $reflectionFunctionLike = $this->reflectFunctionLike($source, $callableExpression);
        } catch (NotFound $exception) {
            $response->issues()->add($exception->getMessage());
            return $response;
        }

        if (null === $reflectionFunctionLike) {
            $response->issues()->add('Could not reflect function / method');
            return $response;
        }

        if ($reflectionFunctionLike->parameters()->count() === 0) {
            $response->issues()->add('Function has no parameters');
            return $response;
        }

        $paramIndex = $this->paramIndex($callableExpression);

        if ($this->numberOfArgumentsExceedParameterArity($reflectionFunctionLike, $paramIndex)) {
            $response->issues()->add('Parameter index exceeds parameter arity');
            return $response;
        }

        $parameter = $this->reflectedParameter($reflectionFunctionLike, $paramIndex);

        $suggestions = [];
        foreach ($variables as $variable) {
            if (
                $variable->symbolContext()->types()->count() && 
                false === $this->isVariableValidForParameter($variable, $parameter)
            ) {
                // parameter has no types and is not valid for this position, ignore it
                continue;
            }

            $response->suggestions()->add(Suggestion::create(
                'v',
                '$' . $variable->name(),
                sprintf(
                    '%s => param #%d %s',
                    $this->formatter->format($variable->symbolContext()->types()),
                    $paramIndex,
                    $this->formatter->format($parameter)
                )
            ));
        }

        return $response;
    }

    private function paramIndex(Node $node)
    {
        $argumentList = $this->argumentListFromNode($node);

        if (null === $argumentList) {
            return 1;
        }

        $index = 0;
        /** @var ArgumentExpression $element */
        foreach ($argumentList->getElements() as $element) {
            $index++;
            if (!$element->expression instanceof Variable) {
                continue;
            }

            $name = $element->expression->getName();

            if ($name instanceof MissingToken) {
                continue;
            }
        }

        return $index;
    }

    private function isVariableValidForParameter(WorseVariable $variable, ReflectionParameter $parameter)
    {
        if ($parameter->inferredTypes()->best() == Type::undefined()) {
            return true;
        }

        $valid = false;

        /** @var Type $variableType */
        foreach ($variable->symbolContext()->types() as $variableType) {

            $variableTypeClass = null;
            if ($variableType->isClass() ) {
                $variableTypeClass = $this->reflector->reflectClassLike($variableType->className());
            }

            foreach ($parameter->inferredTypes() as $parameterType) {
                if ($variableType == $parameterType) {
                    return true;
                }

                if ($variableTypeClass && $parameterType->isClass() && $variableTypeClass->isInstanceOf($parameterType->className())) {
                    return true;
                    
                }

            }
        }
        return false;
    }

    private function reflectedParameter(ReflectionFunctionLike $reflectionFunctionLike, $paramIndex)
    {
        $reflectedIndex = 1;
        /** @var ReflectionParameter $parameter */
        foreach ($reflectionFunctionLike->parameters() as $parameter) {
            if ($reflectedIndex == $paramIndex) {
                return $parameter;
                break;
            }
            $reflectedIndex++;
        }

        throw new LogicException(sprintf('Could not find parameter for index "%s"', $paramIndex));
    }

    private function numberOfArgumentsExceedParameterArity(ReflectionFunctionLike $reflectionFunctionLike, $paramIndex)
    {
        return $reflectionFunctionLike->parameters()->count() < $paramIndex;
    }

    private function reflectFunctionLike(string $source, Node $callableExpression): ?ReflectionFunctionLike
    {
        $offset = $this->reflector->reflectOffset($source, $callableExpression->getEndPosition());

        if ($containerType = $offset->symbolContext()->containerType()) {
            $containerClass = $this->reflector->reflectClassLike($containerType->className());
            return $containerClass->methods()->get($offset->symbolContext()->symbol()->name());
        }

        if (!$callableExpression instanceof QualifiedName) {
            return null;
        }

        $name = $callableExpression->getResolvedName() ?? $callableExpression->getText();

        return $this->reflector->reflectFunction((string) $name);
    }

    private function argumentListFromNode(Node $node): ?ArgumentExpressionList
    {
        if ($node instanceof QualifiedName) {
            $callExpression = $node->parent;
            assert($callExpression instanceof CallExpression);
            return $callExpression->argumentExpressionList;
        }
        
        assert($node instanceof MemberAccessExpression);
        assert(null !== $node->parent);

        $list = $node->parent->getFirstDescendantNode(ArgumentExpressionList::class);
        assert($list instanceof ArgumentExpressionList || is_null($list));

        return $list;
    }
}
