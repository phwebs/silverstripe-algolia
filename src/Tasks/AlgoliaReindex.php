<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\DataObject\AlgoliaIndex;
use SilverStripe\ORM\Queries\SQLSelect;

class AlgoliaReindex extends BuildTask
{
    protected $title = 'Algolia Reindex';

    protected $description = 'Algolia Reindex';

    private static $segment = 'AlgoliaReindex';

    private static $batch_size = 20;

    public function run($request)
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        $algoliaIndexTableName = AlgoliaIndex::create()->config()->get('table_name');

        $targetClass = '';
        $filter = '';

        if ($request->getVar('onlyClass')) {
            $targetClass = $request->getVar('onlyClass');
        }

        if ($request->getVar('filter')) {
            $filter = $request->getVar('filter');
        }

        if (!$request->getVar('forceAll') && !$filter) {
            // $filter = 'AlgoliaIndexed IS NULL';
            $filter = '"' . $algoliaIndexTableName . '"."AlgoliaIndexed" IS NULL';
        }

        if ($targetClass) {
            $this->indexClass($targetClass, $filter);
        } else {
            $algoliaService = Injector::inst()->create(AlgoliaService::class);

            // find all classes we have to index and do so
            foreach ($algoliaService->indexes as $index) {
                $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;

                if ($classes) {
                    foreach ($classes as $candidate) {
                        $this->indexClass($candidate, $filter);
                    }
                }
            }
        }
    }

    public function indexClass($targetClass, $filter = '')
    {
        $inst = $targetClass::create();
        $tableName = $inst->config()->get('table_name');

        $algoliaIndexTableName = AlgoliaIndex::create()->config()->get('table_name');

        // Versioned doesn't matter because we are now saving the algolia info into the table "AlgoliaIndex"
        // if ($inst->hasExtension(Versioned::class)) {
        //     $tableName .= '_Live';
        //     $items = Versioned::get_by_stage($targetClass, 'Live', $filter);
        // } else {
            // $leftJoinSql = '"' . $tableName . '"."ID" = "' . $algoliaIndexTableName . '"."ObjectID" and "' . $tableName . '"."ClassName" = "' . $algoliaIndexTableName . '"."ObjectClassName"';
            $leftJoinSql = '"' . $tableName . '"."ID" = "' . $algoliaIndexTableName . '"."ObjectID" and "' . $algoliaIndexTableName . '"."ObjectClassName" = \'' . $inst->CLassName . '\'';
            $items = $inst::get()
                ->leftJoin($algoliaIndexTableName, $leftJoinSql);

            if ($filter) {
                $items = $items->where($filter);
            }
        // }

        // echo $items->dataQuery->query();
        // die();

        $algoliaService = Injector::inst()->create(AlgoliaService::class);
        $count = 0;
        $skipped = 0;
        $errored = 0;
        $total = $items->count();
        $batchSize = $this->config()->get('batch_size');
        $batchesTotal = ($total > 0) ? (ceil($total / $batchSize)) : 0;
        $indexer = Injector::inst()->create(AlgoliaIndexer::class);

        echo sprintf(
            'Found %s %s remaining to index, will export in batches of %s, %s batches total %s',
            $total,
            $targetClass,
            $batchSize,
            $batchesTotal,
            PHP_EOL
        );

        $pos = 0;

        if ($total < 1) {
            return;
        }

        $currentBatches = [];

        for ($i = 0; $i < $batchesTotal; $i++) {
            $limitedSize = $items->sort('ID', 'DESC')->limit($batchSize, $i * $batchSize);

            foreach ($limitedSize as $item) {
                $pos++;

                echo '.';

                if ($pos % 50 == 0) {
                    echo sprintf(' [%s/%s]%s', $pos, $total, PHP_EOL);
                }

                // fetch the actual instance
                $instance = DataObject::get_by_id($item->ClassName, $item->ID);

                if (!$instance || !$instance->canIndexInAlgolia()) {
                    $skipped++;
                    // echo ' skipping ';
                    continue;
                }

                $batchKey = get_class($item);

                if (!isset($currentBatches[$batchKey])) {
                    $currentBatches[$batchKey] = [];
                }

                $item->touchAlgoliaIndexedDate();

                $currentBatches[$batchKey][] = $indexer->exportAttributesFromObject($item)->toArray();

                $count++;

                if (count($currentBatches[$batchKey]) >= $batchSize) {

                    $this->indexBatch($currentBatches[$batchKey]);

                    unset($currentBatches[$batchKey]);

                    sleep(1);
                }
            }
        }

        foreach ($currentBatches as $class => $records) {
            if (count($currentBatches[$class]) > 0) {
                $this->indexbatch($currentBatches[$class]);

                sleep(1);
            }
        }

        Debug::message(
            sprintf(
                "Number of objects indexed: %s, Errors: %s, Skipped %s",
                $count,
                $errored,
                $skipped
            )
        );

        Debug::message(
            sprintf(
                "See index at <a href='https://www.algolia.com/apps/%s/explorer/indices' target='_blank'>".
                "algolia.com/apps/%s/explorer/indices</a>",
                $algoliaService->applicationId,
                $algoliaService->applicationId
            )
        );
    }

    /**
     * Index a batch of changes
     *
     * @param array $items
     *
     * @return bool
     */
    public function indexBatch($items)
    {
        $indexes = Injector::inst()->create(AlgoliaService::class)->initIndexes($items[0]);

        try {
            foreach ($indexes as $index) {
                $index->saveObjects($items);
            }

            return true;
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);

            if (Director::isDev()) {
                Debug::message($e->getMessage());
            }

            return false;
        }
    }
}
