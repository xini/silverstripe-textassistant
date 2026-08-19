<?php

namespace S2Hub\TextAssistant\Jobs;

use Exception;
use DNADesign\Elemental\Models\BaseElement;
use S2Hub\TextAssistant\Models\TranslationAction_ObjectQueue;
use S2Hub\TextAssistant\Forms\BatchActions_TranslateForm;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Queries\SQLUpdate;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\State\FluentState;

class QueuePageTranslationsJob extends AbstractQueuedJob
{
    /**
     * Prevent cycles in the relation graph from causing unbounded recursion.
     * The key includes the class because IDs are only unique per table/class.
     */
    private $visitedObjects = [];

    public function getTitle()
    {
        return _t(self::class.'.TITLE', 'Initializing translations');
    }

    public function setup()
    {
        parent::setup();

        if (empty($this->jobData->ids)) {
            throw new Exception("No IDs provided for translation job");
        }

        if (empty($this->jobData->fromLocale)) {
            throw new Exception("No fromLocale provided for translation job");
        }

        if (empty($this->jobData->toLocale)) {
            throw new Exception("No toLocale provided for translation job");

        }

        $remaining = $this->jobData->ids;

        $this->remaining = $remaining;

        $this->visitedObjects = [];

        $this->totalSteps = count($this->remaining);
    }

    public function process()
    {
        $remaining = $this->remaining;

        // check for trivial case
        if (count($remaining) === 0) {
            $this->isComplete = true;

            return;
        }

        $item = array_shift($remaining);

        // Cycle detection is needed for the current relation graph, but does
        // not need to retain every object from the whole translation job.
        $this->visitedObjects = [];

        try {
            FluentState::singleton()->withState(function (FluentState $state) use ($item, $remaining) {
                $state->setLocale($this->jobData->fromLocale);

                $this->queue($item);
            });
        } finally {
            // The job may be serialised between process() calls, so do not
            // rely on the static insert buffer surviving until completion.
            TranslationAction_ObjectQueue::insertRemains();
        }

        $this->remaining = $remaining;

        $this->currentStep += 1;

        // check for job completion
        if (count($remaining) > 0) {
            return;
        }

        $this->queueTranslationJobs();
		TranslationAction_ObjectQueue::resetQueueState();
		
        // Queue runner will mark this job as finished
        $this->isComplete = true;
    }

    public function queue($item)
    {
        $fromLocale = $this->jobData->fromLocale;
        $toLocale = $this->jobData->toLocale;
        $group = $this->jobData->group;
        $userChoices = $this->jobData->options;

        if ($item == 0) return;

        $page = DataObject::get_by_id(SiteTree::class, $item);
        TranslationAction_ObjectQueue::queue($page, $group);

        $options = BatchActions_TranslateForm::getTranslationRelationsForPage($page);

        foreach ($options as $option) {

            // only queue if user selected this option
            if (isset($userChoices[$page->ID][$option->getObjectType() . "_" . $option->getName()]) && $userChoices[$page->ID][$option->getObjectType() . "_" . $option->getName()] == 1) {

                $list = $option->getList();
    
                foreach ($list as $item) {
                    $this->queueObject($item, $group);

                    $relatedToQueue = $option->getRelatedObjectsToQueueForObject($item);

                    if (!empty($relatedToQueue)) {

                        foreach($relatedToQueue as $relatedItem) {
                            foreach($relatedItem as $relatedObject) {
                                $this->queueObject($relatedObject, $group);
                            }
                        }
                    }
					unset($relatedToQueue);
                }
                unset($list);
            }
        }
        $page->destroy();
        unset($page);
        unset($options);
    }

    public function queueTranslationJobs()
    {
        $group = $this->jobData->group;

        if (empty($group)) throw new Exception("Group may not be empty");

        $objects = DataList::create(TranslationAction_ObjectQueue::class)->filter('GroupIdentifier', $group);

        $total = $objects->count();
        $chunk_max_size = 50;
        $parts = (int) ceil($total / $chunk_max_size);

        for($i = 0; $i < $parts; $i++) {
            $chunk = $objects->limit($chunk_max_size, $i * $chunk_max_size);

            $job = new ObjectTranslationJob();
            $jobData = new \stdClass();
            $jobData->fromLocale = $this->jobData->fromLocale;
            $jobData->toLocale = $this->jobData->toLocale;
            $jobData->ids = $chunk->column('ID');
            $jobData->CreatorID = $this->jobData->CreatorID;
            $job->setJobData(0, 0, false, $jobData, []);

            $descriptorID = QueuedJobService::singleton()->queueJob($job, null, null, QueuedJob::IMMEDIATE);

            // Update TotalSteps so we can show nice graphics how much there is left to do in TranslationsAdmin
            // Since we're computing ->setup() this makes everything rather slow!
            if ($descriptorID) {
                $job->setup();
                SQLUpdate::create("QueuedJobDescriptor", ['TotalSteps' => sizeof($job->remaining)], ['ID' => $descriptorID])->execute();
            }
            unset($descriptorID);
            unset($chunk);
            unset($job);
            unset($jobData);
        }
        unset($objects);
    }

    public function queueObject(DataObject $object, $group)
    {
        if (!$object || !$object->exists()) {
            return;
        }

        $objectKey = get_class($object) . ':' . $object->ID;
        if (isset($this->visitedObjects[$objectKey])) {
            return;
        }
        $this->visitedObjects[$objectKey] = true;

        TranslationAction_ObjectQueue::queue($object, $group);

        // go through all relations and queue them as well
        // has_one and belongs_to
        $relations = $object->hasOne();
        $relations = array_merge($relations, $object->belongsTo());
        if (count($relations)) {
            foreach ($relations as $relation => $class) {
                if (!in_array($relation, ['Parent'])) {
                    $relationObject = $object->$relation();
                    if ($relationObject && $relationObject->exists()) {
                        if (is_a($relationObject, SiteTree::class)
                            || is_a($relationObject, BaseElement::class)
                            || !$relationObject->hasExtension(FluentExtension::class)
                        ) {
                            continue;
                        }
                        $this->queueObject($relationObject, $group);
                    }
                    $relationObject->destroy();
                    unset($relationObject);
                }
            }
        }
        // has_many and many_many
        $relations = $object->hasMany();
        $relations = array_merge($relations, $object->manyMany());
        if (count($relations)) {
            foreach ($relations as $relation => $class) {
                if (!in_array($relation, ['VirtualPages', 'BackLinks'])) {
                    foreach ($object->$relation() as $relationObject) {
                        if ($relationObject && $relationObject->exists()) {
                            if (is_a($relationObject, SiteTree::class)
                                || is_a($relationObject, BaseElement::class)
                                || !$relationObject->hasExtension(FluentExtension::class)
                            ) {
                                continue;
                            }
                            $this->queueObject($relationObject, $group);
                        }
                        $relationObject->destroy();
                        unset($relationObject);
                    }
                }
            }
        }
        $object->destroy();
        unset($object);
        unset($relations);
        gc_collect_cycles();
    }
}