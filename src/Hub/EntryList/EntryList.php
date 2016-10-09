<?php
namespace Hub\EntryList;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config as SymfonyConfig;
use GuzzleHttp as Guzzle;
use Hub\EntryList\SourceProcessor\SourceProcessorInterface;
use Hub\Entry\EntryInterface;

/**
 * Base List class for providing common functions.
 *
 * @package AwesomeHub
 */
abstract class EntryList implements EntryListInterface
{
    /**
     * @var string
     */
    protected $path;

    /**
     * @var array
     */
    protected $data;

    /**
     * Constructor.
     *
     * @param string $path Path to the list definition file
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct($path)
    {
        if(!file_exists($path)){
            throw new \InvalidArgumentException("Unable to find the list definition file at '$path'.");
        }

        $this->path = $path;
        $encodedData = file_get_contents($path);
        if(empty($encodedData)){
            throw new \InvalidArgumentException("Empty list definition file provided at '$path'.");
        }

        try {
            $data = $this->parse($encodedData);
        }
        catch (\Exception $e){
            throw new \RuntimeException("Unable to parse list definition file at '{$this->path}'; {$e->getMessage()}", 0, $e);
        }

        try {
            $this->data = $this->verify($data);
        }
        catch (SymfonyConfig\Definition\Exception\Exception $e) {
            throw new \RuntimeException("Unable to process the list definition file at '{$this->path}'; {$e->getMessage()}.", 0, $e);
        }
    }

    /**
     * @inheritdoc
     */
    public function process(LoggerInterface $logger, array $processors, $force = false)
    {
        if($this->isProcessed() && !$force){
            throw new \LogicException("Cannot process the list '$this->path' since it's already processed.");
        }

        $entries = [];
        foreach ($this->data['sources'] as $index => $source){
            $sourceEntries = [];
            foreach ($processors as $processor){
                /** @var SourceProcessorInterface $processor */
                if($processor->supports($source)){
                    $logger->info("Processing source#$index with '" . get_class($processor) . "'");
                    $sourceEntries = $processor->process($logger, $source);
                    break;
                }
            }

            if(false === $sourceEntries){
                $logger->critical("Failed processing source#$index of type '{$source['type']}'");
                continue;
            }

            $logger->info("Processing source#$index completed successfully");
            $entries = array_merge_recursive($entries, $sourceEntries);
        }

        $logger->info("Organizing resulted categories and entries");

        $id = 1;
        $categories = [];
        $saved = [];
        foreach ($entries as $category => $categoryEntries) {
            $category = $this->getCategoryName($category);
            $categoryPath = $this->getCategoryPath($category);
            if(!$categoryPath){
                $categoryPath[] = $category;
            }

            $categoryPathIds = [];
            $parent = 0;
            foreach ($categoryPath as $pathSegment) {
                if(in_array($pathSegment, $saved)){
                    $savedId = array_search($pathSegment, $saved);
                    $categoryPathIds[] = $savedId;
                    $parent = $savedId;
                    continue;
                }

                $saved[$id] = $pathSegment;
                $categories[$id] = [
                    'id' => $id,
                    'title' => ucfirst($pathSegment),
                    'parent' => $parent
                ];

                $categoryPathIds[] = $id;
                $parent = $id;
                $id++;
            }

            foreach ($categoryEntries as $entry) {
                /* @var EntryInterface $entry */
                $entry->set('categories', $categoryPathIds);

                // Update category counts for all parent categories
                $type = $entry::TYPE;
                foreach ($categoryPathIds as $categoryPathId) {
                    if(!isset($categories[$categoryPathId]['count'][$type])){
                        $categories[$categoryPathId]['count'][$type] = 1;
                        continue;
                    }

                    $categories[$categoryPathId]['count'][$type]++;
                }
            }
        }

        // Flatten entries array
        $entries = call_user_func_array('array_merge', $entries);

        $this->data['processed'] = true;
        $this->data['categories'] = $categories;
        $this->data['entries'] = $entries;

        return true;
    }

    /**
     * @inheritdoc
     */
    public function resolve(LoggerInterface $logger, $force = false)
    {
        if($this->isResolved() && !$force){
            throw new \LogicException("Cannot resolve the list '$this->path' since it's already resolved.");
        }

        //TODO: write this

        return $this->data['resolved'] = true;
    }

    /**
     * @inheritdoc
     */
    public function isProcessed()
    {
        return (bool) $this->data['processed'];
    }

    /**
     * @inheritdoc
     */
    public function isResolved()
    {
        return (bool) $this->data['resolved'];
    }

    /**
     * Parses the list file data and returns the output.
     *
     * @param string $data List file contents
     * @return array
     */
    abstract protected function parse($data);

    /**
     * Verifies list definition array and returns the processed array.
     *
     * @param array $data List data
     * @return array Processed list definition array
     */
    protected function verify($data)
    {
        return (new SymfonyConfig\Definition\Processor())->processConfiguration(
            new EntryListDefinition(),
            [ $data ]
        );
    }

    /**
     * Gets a new name for the category if defined in options.categoryNames.
     *
     * @param string $name
     * @return string
     */
    protected function getCategoryName($name)
    {
        $names = $this->data['options']['categoryNames'];
        if(isset($names[$name])){
            return $names[$name];
        }

        return $name;
    }

    /**
     * Gets the path to a category within the category tree if defined.
     *
     * @param $category
     * @param $tree
     * @param int $depth
     * @return array|bool
     */
    protected function getCategoryPath($category, array $tree = null, $depth = 0)
    {
        if($tree === null){
            $tree = $this->data['options']['categoryTree'];
        }

        $path = [];
        foreach($tree as $parent => $child) {
            if($depth === 0){
                $path = [];
            }

            if(is_array($child)){
                $path[] = $parent;
            }
            else if($category === $child) {
                $path[] = $category;
            }

            if($category === $parent) {
                return $path;
            }

            if(is_array($child)){
                if(in_array($category, $child)){
                    $path[] = $category;
                    return $path;
                }

                $path = array_merge($path, $this->getCategoryPath($category, $child, $depth + 1));
            }

            if(in_array($category, $path)){
                return $path;
            }
        }

        if($depth === 0 && !in_array($category, $path)){
            return false;
        }

        return $path;
    }
}