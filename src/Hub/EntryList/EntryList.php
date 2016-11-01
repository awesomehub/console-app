<?php

namespace Hub\EntryList;

use Symfony\Component\Config as SymfonyConfig;
use Hub\IO\IOInterface;
use Hub\Entry\EntryInterface;
use Hub\EntryList\SourceProcessor\SourceProcessorInterface;
use Hub\Entry\Resolver\EntryResolverInterface;
use Hub\Exceptions\EntryResolveFailedException;
use Hub\Exceptions\SourceProcessorFailedException;

/**
 * The Base List class.
 */
class EntryList implements EntryListInterface
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @var bool
     */
    protected $resolved = false;

    /**
     * Constructor.
     *
     * @param array $data List definition
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct(array $data)
    {
        try {
            $this->data = $this->verify($data);
        } catch (SymfonyConfig\Definition\Exception\Exception $e) {
            throw new \InvalidArgumentException("Unable to process the list definition data; {$e->getMessage()}.", 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return strtolower($this->get('id'));
    }

    /**
     * {@inheritdoc}
     */
    public function get($key = null)
    {
        if (null === $key) {
            return $this->data;
        }

        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException(sprintf("Trying to get an undefined list data key '%s'", $key));
        }

        return $this->data[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value = null)
    {
        if ($value == null) {
            if (!is_array($key)) {
                throw new \UnexpectedValueException(sprintf('Expected array but got %s'), var_export($key));
            }

            $this->data = $key;

            return;
        }

        $this->data[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function process(IOInterface $io, array $processors)
    {
        if (empty($processors)) {
            throw new \LogicException('Cannot process the list; No source processors has been provided.');
        }

        $logger = $io->getLogger();
        $logger->info('Processing list sources');
        $io->startOverwrite();
        $indicator = ' [ %%spinner%% ] Processesing source#%d => %s (%%elapsed%%)';

        $s          = 0;
        $entriesMap = [];
        foreach ($this->data['sources'] as $index => $source) {
            $sourceEntries = [];
            $processedWith = false;
            /** @var SourceProcessorInterface $processor */
            foreach ($processors as $processor) {
                if ($processor->supports($source)) {
                    $processorName = basename(str_replace('\\', '/', get_class($processor)));
                    $logger->info(sprintf("Processing source#%d with '%s'", $index, $processorName));
                    $processedWith = $processor;
                    try {
                        $sourceEntries = $processor->process($source, function ($event, $entry, $message) use ($logger, $io, $index, $indicator) {
                            switch ($event) {
                                case SourceProcessorInterface::EVENT_ENTRY_CREATE:
                                    $io->write(sprintf($indicator, $index, $entry));
                                    break;
                                case SourceProcessorInterface::EVENT_ENTRY_FAILED:
                                    $logger->warning($message);
                                    break;
                            }
                        });
                    } catch (SourceProcessorFailedException $e) {
                        $logger->critical(sprintf("Failed processing source#%d of type '%s' with '%s'.", $index, $source['type'], get_class($processor)));
                        continue 2;
                    }
                    break;
                }
            }

            // Check if no processor can process this source
            if (false === $processedWith) {
                $logger->critical(sprintf("Ignoring source#%d of type '%s'; None of the given processors supports it.", $index, $source['type']));
                continue;
            }

            $logger->info(sprintf('Processing source#%d completed successfully', $index));
            $entriesMap = array_merge_recursive($entriesMap, $sourceEntries);
            ++$s;
        }

        $logger->info(sprintf('Processed %d/%d source(s)', $s, count($this->data['sources'])));

        $io->endOverwrite();

        $logger->info('Organizing resulted categories and entries');

        $id         = 1;
        $categories = [];
        $entries    = [];
        $saved      = [];
        foreach ($entriesMap as $category => $categoryEntries) {
            $categoryPath = $this->getCategoryPath($category);
            if (!$categoryPath) {
                $categoryPath[] = $category;
            }

            $categoryPathIds = [];
            $parent          = 0;
            foreach ($categoryPath as $pathSegment) {
                if (in_array($pathSegment, $saved)) {
                    $savedId           = array_search($pathSegment, $saved);
                    $categoryPathIds[] = $savedId;
                    $parent            = $savedId;
                    continue;
                }

                $saved[$id]      = $pathSegment;
                $categories[$id] = [
                    'id'     => $id,
                    'title'  => ucfirst($pathSegment),
                    'parent' => $parent,
                ];

                $categoryPathIds[] = $id;
                $parent            = $id;
                ++$id;
            }

            foreach ($categoryEntries as $entry) {
                /* @var EntryInterface $entry */
                $entryId   = $entry->getId();
                $entryType = $entry->getType();
                if (isset($entries[$entryId])) {
                    $entry = $entries[$entryId];
                }

                // Merge to preserve previous categories if any
                $entry->merge('categories', $categoryPathIds);

                // Update category counts for all parent categories
                foreach ($categoryPathIds as $categoryPathId) {
                    // Update the sum count
                    if (isset($categories[$categoryPathId]['count']['all'])) {
                        ++$categories[$categoryPathId]['count']['all'];
                    } else {
                        $categories[$categoryPathId]['count']['all'] = 1;
                    }

                    // Update entry type count
                    if (isset($categories[$categoryPathId]['count'][$entryType])) {
                        ++$categories[$categoryPathId]['count'][$entryType];
                    } else {
                        $categories[$categoryPathId]['count'][$entryType] = 1;
                    }
                }

                $entries[$entryId] = $entry;
            }
        }

        $this->data['categories'] = $categories;
        $this->data['entries']    = $entries;

        $logger->info(sprintf(
            'Organized %d category(s) and %d entry(s)',
            count($categories),
            count($entries)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(IOInterface $io, array $resolvers, $force = false)
    {
        if (empty($resolvers)) {
            throw new \LogicException('Cannot resolve the list; No resolvers has been provided');
        }

        if (empty($this->data['entries'])) {
            throw new \LogicException('No entries to resolve');
        }

        $logger = $io->getLogger();

        $logger->info('Resolving list entries');
        $io->startOverwrite();
        $indicator = ' [ %%spinner%% ] Resolving entry#%d => %s (%%elapsed%%)';

        $i = $ir = $ic = 0;
        /* @var EntryInterface $entry */
        foreach ($this->data['entries'] as $id => $entry) {
            ++$i;
            $resolvedWith = false;
            $isCached     = false;

            /* @var EntryResolverInterface $resolver */
            foreach ($resolvers as $resolver) {
                if ($resolver->supports($entry)) {
                    $resolvedWith = $resolver;
                    $isCached     = $resolver->isResolved($entry);
                    $io->write(sprintf($indicator, $i, $id));
                    try {
                        $resolver->resolve($entry, $force);
                    } catch (EntryResolveFailedException $e) {
                        $this->removeEntry($entry);
                        $logger->warning(sprintf("Failed resolving entry#%d [%s] with '%s'; %s", $i, $id, get_class($resolver), $e->getMessage()));
                        continue 2;
                    }

                    break;
                }
            }

            // Check if no resolver can resolve this entry
            if (false === $resolvedWith) {
                $this->removeEntry($entry);
                $logger->warning(sprintf("Ignoring entry#%d [%s] of type '%s'; None of the given resolvers supports it", $i, $id, get_class($entry)));
                continue;
            }

            if ($isCached) {
                ++$ic;
            }

            ++$ir;
        }

        $this->resolved = true;
        $logger->info(sprintf('Resolved %d/%d entry(s) with %d cached entry(s)',
            $ir, $i, $ic
        ));
        $io->endOverwrite();
    }

    /**
     * {@inheritdoc}
     */
    public function isResolved()
    {
        return $this->resolved;
    }

    /**
     * @inheritdoc
     */
    public function removeEntry(EntryInterface $entry)
    {
        // Remove from entries
        $entries = $this->get('entries');
        unset($entries[$entry->getId()]);
        $this->set('entries', $entries);

        // Update cat counts
        $categories = $this->get('categories');
        foreach ($categories as $i => $category) {
            if(in_array($category['id'], $entry->get('categories'))){
                --$categories[$i]['count']['all'];
                --$categories[$i]['count'][$entry->getType()];

                // Remove the category if it hs no entries
                if(1 > $categories[$i]['count']['all']){
                    unset($categories[$i]);
                }
            }
        }
        $this->set('categories', $categories);
    }

    /**
     * Verifies list definition array and returns the processed array.
     *
     * @param array $data List data
     *
     * @return array Processed list definition array
     */
    protected function verify($data)
    {
        return (new SymfonyConfig\Definition\Processor())->processConfiguration(
            new EntryListDefinition(),
            [$data]
        );
    }

    /**
     * Gets the path to a category within the category tree if defined.
     *
     * @param $category
     * @param $tree
     * @param int $depth
     *
     * @return array|bool
     */
    protected function getCategoryPath($category, array $tree = null, $depth = 0)
    {
        if ($tree === null) {
            $tree = $this->data['options']['categoryTree'];
        }

        $path = [];
        foreach ($tree as $parent => $child) {
            if ($depth === 0) {
                $path = [];
            }

            if (is_array($child)) {
                $path[] = $parent;
            } elseif ($category === $child) {
                $path[] = $category;
            }

            if ($category === $parent) {
                return $path;
            }

            if (is_array($child)) {
                if (in_array($category, $child)) {
                    $path[] = $category;

                    return $path;
                }

                $path = array_merge($path, $this->getCategoryPath($category, $child, $depth + 1));
            }

            if (in_array($category, $path)) {
                return $path;
            }
        }

        if ($depth === 0 && !in_array($category, $path)) {
            return false;
        }

        return $path;
    }
}
