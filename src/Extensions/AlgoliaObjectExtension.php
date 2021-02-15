<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Ramsey\Uuid\Uuid;
use SilverStripe\Core\Convert;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaDeleteItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaIndexItemJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\DataObject\AlgoliaIndex;
use SilverStripe\ORM\Queries\SQLUpdate;

class AlgoliaObjectExtension extends DataExtension
{
    use Configurable;

    /**
     * @config
     *
     * @var boolean
     */
    private static $enable_indexer = true;

    /**
     * @config
     *
     * @var boolean
     */
    private static $use_queued_indexing = false;

    // private static $db = [
    //     'AlgoliaIndexed' => 'Datetime',
    //     'AlgoliaUUID' => 'Varchar(200)',
    //     'AlgoliaError' => 'Varchar(200)'
    // ];

    public $algoliaIndexObject = null;

    /**
     * @return bool
     */
    public function indexEnabled(): bool
    {
        return $this->config('enable_indexer') ? true : false;
    }

    /**
     * @param FieldList
     */
    public function updateSettingsFields(FieldList $fields)
    {
        if ($this->owner->indexEnabled()) {
            $algoliaIndex = $this->algoliaGetAlgoliaIndexObject();
            $fields->addFieldsToTab(
                'Root.AlgoliaSearch',
                [
                ReadonlyField::create('AlgoliaIndexed', _t(__CLASS__.'.LastIndexed', 'Last indexed in Algolia'), $algoliaIndex->AlgoliaIndexed)
                    ->setDescription($algoliaIndex->AlgoliaError),
                ReadonlyField::create('AlgoliaUUID', _t(__CLASS__.'.UUID', 'Algolia objectID'), $algoliaIndex->AlgoliaUUID)
                ]
            );
        }
    }

    /**
     * On dev/build ensure that the indexer settings are up to date.
     */
    public function requireDefaultRecords()
    {
        $algolia = Injector::inst()->create(AlgoliaService::class);
        $algolia->syncSettings();
    }

    /**
     * Returns whether this object should be indexed into Algolia.
     */
    public function canIndexInAlgolia(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->ShowInSearch;
        }

        return true;
    }

    /**
     * When publishing the page, push this data to Algolia Indexer. The data
     * which is sent to Algolia is the rendered template from the front end.
     */
    public function onAfterPublish()
    {
        if (min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
            $this->owner->removeFromAlgolia();
        } else {
            // check to see if the classname changed, if it has then it might
            // need to be removed from other indexes before being re-added
            if ($this->owner->isChanged('ClassName')) {
                $this->owner->removeFromAlgolia();
            }

            $this->owner->indexInAlgolia();
        }
    }

    /**
     *
     */
    public function markAsRemovedFromAlgoliaIndex()
    {
        $this->touchAlgoliaIndexedDate(true);
    }

    /**
     * Update the AlgoliaIndexed date for this object.
     */
    public function touchAlgoliaIndexedDate($isDeleted = false)
    {
        $this->algoliaUpdateDB([
            'AlgoliaIndexed' => $isDeleted ? null : date('Y-m-d H:i:s'),
            'AlgoliaError' => null,
        ]);

        return $this->owner;
    }

    /**
     * Index this record into Algolia or queue if configured to do so
     *
     * @return bool
     */
    public function indexInAlgolia(): bool
    {
        if ($this->owner->indexEnabled() && min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
            return false;
        }

        if ($this->config()->get('use_queued_indexing')) {
            $indexJob = new AlgoliaIndexItemJob($this->owner->ClassName, $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexJob);

            return true;
        } else {
            return $this->doImmediateIndexInAlgolia();
        }
    }

    /**
     * Index this record into Algolia
     *
     * @return bool
     */
    public function doImmediateIndexInAlgolia()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        try {
            $indexer->indexItem($this->owner);
            $this->touchAlgoliaIndexedDate();

            return true;
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);

            $this->algoliaUpdateDB(['AlgoliaError' => Convert::raw2sql($e->getMessage())]);

            return false;
        }
    }

    /**
     * When unpublishing this item, remove from Algolia
     */
    public function onAfterUnpublish()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * Remove this item from Algolia
     *
     * @return boolean false if failed or not indexed
     */
    public function removeFromAlgolia()
    {
        $algoliaIndex = $this->algoliaGetAlgoliaIndexObject();
        if (!$algoliaIndex->AlgoliaIndexed) {
            // Not in the index, so skipping
            return false;
        }

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        if ($this->config()->get('use_queued_indexing')) {
            $indexDeleteJob = new AlgoliaDeleteItemJob($this->owner->getClassName(), $algoliaIndex->AlgoliaUUID);

            QueuedJobService::singleton()->queueJob($indexDeleteJob);

            $this->markAsRemovedFromAlgoliaIndex();
        } else {
            try {
                $indexer->deleteItem($this->owner->getClassName(), $algoliaIndex->AlgoliaUUID);

                $this->markAsRemovedFromAlgoliaIndex();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
                return false;
            }
        }
        return true;
    }

    // public function onBeforeWrite()
    // {
    //     if (!$this->owner->AlgoliaUUID) {
    //         $this->owner->assignAlgoliaUUID();
    //     }
    // }

    public function assignAlgoliaUUID()
    {
        $this->algoliaGetAlgoliaIndexObject();

        return $this->owner;
    }

    /**
     * Before deleting this record ensure that it is removed from Algolia.
     */
    public function onBeforeDelete()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromAlgolia();
        }

        $algoliaIndex = $this->algoliaGetAlgoliaIndexObject();
        $algoliaIndex->delete();
    }

    /**
     * Ensure each record has unique UUID
     */
    // public function onBeforeDuplicate()
    // {
    //     $this->owner->AlgoliaUUID = null;
    //     $this->owner->assignAlgoliaUUID();
    //     $this->owner->AlgoliaIndexed = null;
    //     $this->owner->AlgoliaError = null;
    // }

    /**
     * @return array
     */
    public function getAlgoliaIndexes()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        return $indexer->getService()->initIndexes($this->owner);
    }

    public function algoliaUpdateDB($data = [])
    {

        $algoliaIndex = $this->algoliaGetAlgoliaIndexObject();
        if (count($data) > 0) {
            foreach ($data as $column => $value) {
                $algoliaIndex->{$column} = $value;
            }
            $algoliaIndex->write();
        }
        // } else {
        //     $algoliaIndex->AlgoliaIndexed = $this->owner->AlgoliaIndexed;
        //     $algoliaIndex->AlgoliaError = $this->owner->AlgoliaError;
        //     $algoliaIndex->AlgoliaUUID = $this->owner->AlgoliaUUID;
        // }


        // $schema = DataObject::getSchema();
        // $table = $schema->tableForField($this->owner->ClassName, 'AlgoliaIndexed');
        //
        // $update = SQLUpdate::create('"' . $table . '"')->addWhere(['ID' => $this->owner->ID]);
        // if (count($data) > 0) {
        //     foreach ($data as $column => $value) {
        //         if ($column == 'AlgoliaIndexed') {
        //             if ($this->owner->AlgoliaIndexed === 'null' || $this->owner->AlgoliaIndexed === 'NOW()') {
        //                 $update->assignSQL('"AlgoliaIndexed"', $this->owner->AlgoliaIndexed);
        //             } else {
        //                 $update->assign('"AlgoliaIndexed"', $this->owner->AlgoliaIndexed);
        //             }
        //         } else {
        //             $update->assign('"' . $column . '"', $value);
        //         }
        //     }
        // } else {
        //     if ($this->owner->AlgoliaIndexed === 'null' || $this->owner->AlgoliaIndexed === 'NOW()') {
        //         $update->assignSQL('"AlgoliaIndexed"', $this->owner->AlgoliaIndexed);
        //     } else {
        //         $update->assign('"AlgoliaIndexed"', $this->owner->AlgoliaIndexed);
        //     }
        //     $update->assign('"AlgoliaError"', $this->owner->AlgoliaError);
        //     $update->assign('"AlgoliaUUID"', $this->owner->AlgoliaUUID);
        // }
        // $update->execute();
        //
        // if ($this->owner->hasExtension('SilverStripe\Versioned\Versioned')) {
        //     $update->setTable('"' . $table . '_Live"');
        //     $update->execute();
        // }

        return $this->owner;
    }

    public function algoliaGetAlgoliaIndexObject()
    {
        if (!$this->owner->algoliaIndexObject) {
            $algoliaIndex = AlgoliaIndex::get()->filter([
                'ObjectID' => $this->owner->ID,
                'ObjectClassName' => $this->owner->ClassName
            ])->first();

            if (!$algoliaIndex || !$algoliaIndex->exists() && $this->owner->ID > 0) {
                $algoliaIndex = AlgoliaIndex::create();
                $algoliaIndex->ObjectID = $this->owner->ID;
                $algoliaIndex->ObjectClassName = $this->owner->ClassName;
                $uuid = Uuid::uuid4();
                $algoliaIndex->AlgoliaUUID = $uuid->toString();
                $algoliaIndex->AlgoliaIndexed = null;
                $algoliaIndex->write();
            }
            $this->owner->algoliaIndexObject = $algoliaIndex;
        }

        return $this->owner->algoliaIndexObject;
    }
}
