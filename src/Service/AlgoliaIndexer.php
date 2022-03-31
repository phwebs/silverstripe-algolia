<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Map;
use SilverStripe\ORM\RelationList;
use stdClass;
use SilverStripe\Control\Director;
use Wilr\SilverStripe\Algolia\DataObject\AlgoliaIndex;
use SilverStripe\BBCodeParser\BBCodeParser;
use hpa\forum\Post;
use SilverStripe\Blog\Model\BlogPost;
use hpa\mysite\CourseComponent;
use hpa\mysite\CourseEntry;
use hpa\mysite\TechArticle;
use hpa\mysite\OwnedCourse;
use hpa\mysite\ArchivedWebinar;
use hpa\mysite\QAndAVideoPage;

/**
 * Handles all the index management and communication with Algolia. Note that
 * any checking of records should be performed by the caller of these methods as
 * no permission checking is done by this class
 */
class AlgoliaIndexer
{
    use Configurable;

    /**
     * Include rendered markup from the object's `Link` method in the index.
     *
     * @config
     */
    private static $include_page_content = true;

    /**
     * @config
     */
    private static $attributes_blacklisted = [
        'ID', 'Title', 'ClassName', 'LastEdited', 'Created'
    ];

    /**
     * @config
     */
    private static $max_field_size_bytes = 10000;

    /**
     * Add the provided item to the Algolia index.
     *
     * Callee should check whether this object should be indexed at all. Calls
     * {@link exportAttributesFromObject()} to determine what data should be
     * indexed
     *
     * @param DataObject $item
     *
     * @return $this
     */
    public function indexItem($item)
    {
        $searchIndexes = $this->getService()->initIndexes($item);
        $fields = $this->exportAttributesFromObject($item);

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->saveObject($fields->toArray());
        }

        return $this;
    }

    /**
     * @return AlgoliaService
     */
    public function getService()
    {
        return Injector::inst()->get(AlgoliaService::class);
    }

    /**
     * Index multiple items of the same class at a time.
     *
     * @param DataObject[] $items
     *
     * @return $this
     */
    public function indexItems($items)
    {
        $searchIndexes = $this->getService()->initIndexes($items->first());
        $data = [];

        foreach ($items as $item) {
            $data[] = $this->exportAttributesFromObject($item)->toArray();
        }

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->saveObjects($data);
        }

        return $this;
    }

    /**
     * Generates a map of all the fields and values which will be sent.
     *
     * @param DataObject
     *
     * @return SilverStripe\ORM\Map
     */
    public function exportAttributesFromObject($item)
    {
        $className = get_class($item);
        $title = (string)$item->Title;

        // https://docs.silverstripe.org/en/4/developer_guides/files/images/#image
        $objectImage = '';
        // Need to check if there is a vimeo video URL before checking for the PreviewImage
        // The vimeo video URL is a more relavant image than the PreviewImage on ArchivedWebinars
        // The ArchivedWebinar's PreviewImage is the same on many of them
        if (isset($item->PreviewVideoMedResImageURL) && $item->PreviewVideoMedResImageURL) { // CourseEntry
            $objectImage = $item->PreviewVideoMedResImageURL;
        } elseif (isset($item->VideoMedResImageURL) && $item->VideoMedResImageURL) { // ArchivedWebinar
            $objectImage = $item->VideoMedResImageURL;
        } elseif ($item->hasMethod('PreviewImage') && $item->PreviewImage()->exists()) {
            $image = $item->PreviewImage()->Pad(400, 400);
            if ($image) {
                $objectImage = $image->AbsoluteURL;
            }
        } elseif ($item->hasMethod('FeaturedImage') && $item->FeaturedImage()->exists()) { // BlogPost
            $image = $item->FeaturedImage()->Pad(400, 400);
            if ($image) {
                $objectImage = $image->AbsoluteURL;
            }
        }

        $objectContent = '';
        if (isset($item->Content) && !empty($item->Content)) {
            $contentObject = $item->dbObject('Content');
            if ($className === Post::class) {
                $objectContent = $contentObject->Parse(BBCodeParser::class);
            } else {
                $objectContent = $contentObject->RAW();
            }
        }

        if (isset($item->SubTitles) && $item->SubTitles) {
            $subTitlesObject = $item->dbObject('SubTitles');
            $objectContent .= (!empty($objectContent) ? "\n\n" : '') . $subTitlesObject->RAW();
        }

        if ($item->hasMethod('AlgoliaExtraContent')) {
            $extraContent = $item->AlgoliaExtraContent();
            if (!empty($extraContent)) {
                $objectContent .= (!empty($objectContent) ? "\n\n" : '') . $extraContent;
            }
        }

        $objectContent = str_replace('><', '> <', $objectContent);
        $objectContent = strip_tags($objectContent);
        $objectContent = \html_entity_decode($objectContent);
        $objectContent = preg_replace('~\s+~', ' ', $objectContent);

        if (strlen($objectContent) > 90000) { // index can be max size of 100,000 bytes
            $objectContent = substr($objectContent, 0, 90000);
        }

        // echo $objectContent;
        // echo "\n\n";
        // die();

        $objectMetaTitle = '';
        if (isset($item->MetaTitle) && !empty($item->MetaTitle)) {
            $objectMetaTitle = strip_tags($item->MetaTitle);
        }

        $objectMetaDescription = '';
        if (isset($item->MetaDescription) && !empty($item->MetaDescription)) {
            $objectMetaDescription = strip_tags($item->MetaDescription);
        }

        $filterIDs = [];
        if ($item->hasMethod('Filters') && $item->Filters()->count()) {
            foreach ($item->Filters() as $filter) {
                $filterIDs[] = $filter->ID;
            }
        }

        if ($className === CourseComponent::class) {
            $title = $item->getMetaTitle();
        }

        $jsonData = '';
        $extraDataArray = [];
        if ($className === CourseEntry::class) {
            $modulesCount = (int)$item->Modules()->Count();
            $modulesCountString = $modulesCount . ' module' . ($modulesCount == 1 ? '' : 's');
            $studentsEnrolledCount = OwnedCourse::get()->filter([
                'CourseID' => $item->ID,
            ])->Count();
            $studentsEnrolledCountString = $studentsEnrolledCount . ' student' . ($studentsEnrolledCount == 1 ? '' : 's') . ' enrolled';
            $extraDataArray = [
                'ModulesCount' => $modulesCountString,
                'StudentsEnrolledCount' => $studentsEnrolledCountString,
                'CompetencyLevel' => $item->CompetencyLevel,
                'UnitPrice' => $item->UnitPrice,
            ];
        } elseif ($className === ArchivedWebinar::class || $className === QAndAVideoPage::class) {
            if ($item->VideoDuration > 0) {
                $extraDataArray = [
                    'VideoDuration' => $item->VideoTime(),
                ];
            }
        } elseif ($className === BlogPost::class || $className === TechArticle::class) {
            $content = strip_tags($item->Content);
            if (!empty($content)) {
                $wordCount = str_word_count($content);
                $mintues = floor($wordCount / 200);
                if ($mintues > 0) {
                    $estimatedReadingTime = $mintues . ' minute' . ($mintues == 1 ? '' : 's');
                } else {
                    $estimatedReadingTime = '1 minute';
                }
                $extraDataArray = [
                    'EstimatedReadingTime' => $estimatedReadingTime,
                ];

            }
        }

        // if (is_array($jsonDataArray) && count($jsonDataArray)) {
        //     $jsonData = json_encode($jsonDataArray);
        // }

        $algoliaIndex = $item->algoliaGetAlgoliaIndexObject();
        $toIndex = [
            'objectID' => $algoliaIndex->AlgoliaUUID,
            'objectSilverstripeID' => $item->ID,
            'objectTitle' => $title,
            'objectClassName' => $className,
            'objectClassNameHierarchy' => array_values(ClassInfo::ancestry($className)),
            'objectLastEdited' => $item->dbObject('LastEdited')->getTimestamp(),
            'objectCreated' => $item->dbObject('Created')->getTimestamp(),
            'objectLink' => str_replace(['?stage=Stage', '?stage=Live'], '', $item->Link()),
            'objectContent' => $objectContent,
            'objectImage' => $objectImage,
            'objectMetaTitle' => $objectMetaTitle,
            'objectMetaDescription' => $objectMetaDescription,
            'objectFilterIDs' => $filterIDs,
            'objectExtraData' => $extraDataArray,
        ];

        // ob_start();
        // var_dump($toIndex);
        // $contents = ob_get_clean();
        // file_put_contents('/home/ubuntu/host-dirs/debug.log', $contents . "\n", FILE_APPEND);

        if ($this->config()->get('include_page_content')) {
            $toIndex['objectForTemplate'] =
                Injector::inst()->create(AlgoliaPageCrawler::class, $item)->getMainContent();
        }

        $item->invokeWithExtensions('onBeforeAttributesFromObject');

        $attributes = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $attributes->push($k, $v);
        }

        $specs = $item->config()->get('algolia_index_fields');

        if ($specs) {
            foreach ($specs as $attributeName) {
                if (in_array($attributeName, $this->config()->get('attributes_blacklisted'))) {
                    continue;
                }

                $dbField = $item->relObject($attributeName);
                $maxFieldSize = $this->config()->get('max_field_size_bytes');

                if ($dbField && ($dbField->exists() || $dbField instanceof DBBoolean)) {
                    if ($dbField instanceof RelationList || $dbField instanceof DataObject) {
                        // has-many, many-many, has-one
                        $this->exportAttributesFromRelationship($item, $attributeName, $attributes);
                    } else {
                        // db-field, if it's a date then use the timestamp since we need it
                        $hasContent = true;

                        switch (get_class($dbField)) {
                            case DBDate::class:
                            case DBDatetime::class:
                                $value = $dbField->getTimestamp();
                                break;
                            case DBBoolean::class:
                                $value = $dbField->getValue();
                                break;
                            case DBHTMLText::class:
                                $fieldData = $dbField->Plain();
                                $fieldLength = mb_strlen($fieldData, '8bit');

                                if ($fieldLength > $maxFieldSize) {
                                    $maxIterations = 100;
                                    $i = 0;

                                    while ($hasContent && $i < $maxIterations) {
                                        $block = mb_strcut(
                                            $fieldData,
                                            $i * $maxFieldSize,
                                            $maxFieldSize - 1
                                        );

                                        if ($block) {
                                            $attributes->push($attributeName .'_Block'. $i, $block);
                                        } else {
                                            $hasContent = false;
                                        }

                                        $i++;
                                    }
                                } else {
                                    $value = $fieldData;
                                }
                                break;
                            default:
                                $value = $dbField->forTemplate();
                        }

                        if ($hasContent) {
                            $value = strip_tags($value);
                            $attributes->push($attributeName, $value);
                        }
                    }
                }
            }
        }

        $item->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
    }

    /**
     * Retrieve all the attributes from the related object that we want to add
     * to this record. As the related record may not have the
     *
     * @param DataObject            $item
     * @param string                $relationship
     * @param \SilverStripe\ORM\Map $attributes
     */
    public function exportAttributesFromRelationship($item, $relationship, $attributes)
    {
        try {
            $data = [];

            $related = $item->{$relationship}();

            if (!$related || !$related->exists()) {
                return;
            }

            if (is_iterable($related)) {
                foreach ($related as $relatedObj) {
                    $relationshipAttributes = new Map(ArrayList::create());
                    $relationshipAttributes->push('objectID', $relatedObj->ID);
                    $relationshipAttributes->push('objectTitle', $relatedObj->Title);

                    if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                        $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $relatedObj);
                    }

                    $data[] = $relationshipAttributes->toArray();
                }
            } else {
                $relationshipAttributes = new Map(ArrayList::create());
                $relationshipAttributes->push('objectID', $related->ID);
                $relationshipAttributes->push('objectTitle', $related->Title);

                if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                    $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $related);
                }

                $data = $relationshipAttributes->toArray();
            }

            $attributes->push($relationship, $data);
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }
    }

    /**
     * Remove an item ID from the index. As this would usually be when an object
     * is deleted in Silverstripe we cannot rely on the object existing.
     *
     * @param string $itemClass
     * @param int $itemUUID
     */
    public function deleteItem($itemClass, $itemUUID)
    {

        $algoliaIndex = DataObject::get_one(AlgoliaIndex::class, ['AlgoliaUUID' => $itemUUID]);
        if (!$algoliaIndex || !$algoliaIndex->isInDB()) {
            return false;
        }

        $item = $algoliaIndex->ObjectClassName::get()->byID($algoliaIndex->ObjectID);
        if (!$item || !$item->isInDB()) {
            return false;
        }

        $searchIndexes = $this->getService()->initIndexes();

        foreach ($searchIndexes as $key => $searchIndex) {
            $searchIndex->deleteObject($algoliaIndex->AlgoliaUUID);
        }

        return true;
    }

    /**
     * Generates a unique ID for this item. If using a single index with
     * different dataobjects such as products and pages they potentially would
     * have the same ID. Uses the classname and the ID.
     *
     * @deprecated
     * @param      DataObject $item
     *
     * @return string
     */
    public function generateUniqueID($item)
    {
        return strtolower(str_replace('\\', '_', get_class($item)) . '_'. $item->ID);
    }

    /**
     * @param DataObject $item
     *
     * @return array
     */
    public function getObject($item)
    {
        $indexes = $this->getService()->initIndexes($item);

        foreach ($indexes as $index) {
            try {
                $output = $index->getObject($item);
                if ($output) {
                    return $output;
                }
            } catch (NotFoundException $ex) {
            }
        }
    }
}
