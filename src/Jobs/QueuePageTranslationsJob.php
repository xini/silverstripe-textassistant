<?php

namespace S2Hub\TextAssistant\Jobs;

use Exception;
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
use TractorCow\Fluent\State\FluentState;

class QueuePageTranslationsJob extends AbstractQueuedJob
{

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

        FluentState::singleton()->withState(function (FluentState $state) use ($item, $remaining) {
            $state->setLocale($this->jobData->fromLocale);

            $this->queue($item);
        });

        $this->remaining = $remaining;

        $this->currentStep += 1;

        // check for job completion
        if (count($remaining) > 0) {
            return;
        }

        TranslationAction_ObjectQueue::insertRemains();
        $this->queueTranslationJobs();

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
        $this->queueObject($page, $group);

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
                }
                gc_collect_cycles();
            }
            
        }

    }

    public function queueTranslationJobs()
    {
        $group = $this->jobData->group;

        if (empty($group)) throw new Exception("Group may not be empty");

        $objects = DataList::create(TranslationAction_ObjectQueue::class)->filter('GroupIdentifier', $group);

        $total = $objects->count();
        $chunk_max_size = 50;
        $parts = $total / $chunk_max_size;

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
        }

    }

    public function queueObject(DataObject $object, $group)
    {
        TranslationAction_ObjectQueue::queue($object, $group);

        $translate_relation_option = Config::inst()->get(get_class($object), 'translate_relation_option');

        if ($translate_relation_option !== null) {

            foreach ($translate_relation_option as $relation) {
                $type = $object->getRelationType($relation);

                if (in_array($type, ['has_one', 'belongs_to'])) {

                    $relationObject = $object->$relation();
                    $this->queueObject($relationObject, $group);

                } else if (in_array($type, ['has_many', 'many_many', 'belongs_many_many'])) {

                    foreach ($object->$relation() as $relationObject) {
                        $this->queueObject($relationObject, $group);
                    }

                }

            }

        }

        unset($object);
    }

}