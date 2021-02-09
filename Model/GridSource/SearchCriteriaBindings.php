<?php declare(strict_types=1);

namespace Hyva\Admin\Model\GridSource;

use function array_reduce as reduce;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;

class SearchCriteriaBindings
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Api\FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var \Magento\Framework\Api\Search\FilterGroupBuilder
     */
    private $filterGroupBuilder;

    /**
     * @var mixed[]
     */
    private $bindingsConfig;

    public function __construct(
        ObjectManagerInterface $objectManager,
        FilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder,
        array $bindingsConfig = []
    ) {
        $this->objectManager      = $objectManager;
        $this->filterBuilder      = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->bindingsConfig     = $bindingsConfig;
    }

    public function apply(SearchCriteriaInterface $searchCriteria): SearchCriteriaInterface
    {
        $initialFilterGroups   = $searchCriteria->getFilterGroups();
        $processedFilterGroups = reduce($this->bindingsConfig, [$this, 'applyBinding'], $initialFilterGroups);

        return $searchCriteria->setFilterGroups($processedFilterGroups);
    }

    private function applyBinding(array $filterGroups, array $binding): array
    {
        $filter = $this->filterBuilder->setField($binding['field'] ?? '')
                                      ->setValue($this->fetchBindValue($binding))
                                      ->setConditionType($binding['condition'] ?? 'eq')
                                      ->create();

        $filterGroups[] = $this->filterGroupBuilder->addFilter($filter)->create();

        return $filterGroups;
    }

    private function fetchBindValue(array $binding)
    {
        $typeAndMethod = $binding['method'] ?? (isset($binding['requestParam']) ? RequestInterface::class . '::getParam' : null);

        [$type, $method] = $this->splitTypeAndMethod($typeAndMethod, $binding['field'] ?? '- not specified -');

        $param = $binding['param'] ?? $binding['requestParam'] ?? null;

        $instance = $this->objectManager->get($type);
        $value    = isset($param) ? $instance->{$method}($param) : $instance->{$method}();

        return ($binding['property'] ?? false) && $binding['property'] !== ''
            ? $this->fetchProperty($value, $binding['property'])
            : $value;
    }

    private function fetchProperty($value, string $property)
    {
        if (is_object($value)) {
            return $this->fetchObjectProperty($property, $value);
        }
        if (is_array($value)) {
            return $value[$property];
        }
        $msg = sprintf('Unable to fetch property "%s" from value of type "%s"', $property, gettype($value));
        throw new UnableToFetchPropertyFromValueException($msg);
    }

    private function fetchObjectProperty(string $property, $value)
    {
        $getter = 'get' . SimpleDataObjectConverter::snakeCaseToCamelCase($property);
        if (method_exists($value, $getter)) {
            return $value->{$getter}();
        }
        if (method_exists($value, 'getData')) {
            return $value->getData($property);
        }
        if ($value instanceof \ArrayAccess) {
            return $value[$property];
        }
        if (property_exists($value, $property)) {
            return $value->{$property};
        }
        $msg = sprintf('Unable to fetch property "%s" from an instance of "%s"', $property, get_class($value));
        throw new UnableToFetchPropertyFromValueException($msg);
    }

    private function splitTypeAndMethod(?string $typeAndMethod, string $field): array
    {
        if (!$typeAndMethod || !preg_match('/^.+::.+$/', $typeAndMethod)) {
            $msg = sprintf('Invalid defaultSearchCriteriaBinding "%s" specified: method="%s"', $field, $typeAndMethod);
            throw new \RuntimeException($msg);
        }
        return explode('::', $typeAndMethod);
    }
}
