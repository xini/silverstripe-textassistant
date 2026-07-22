<?php

namespace S2Hub\TextAssistant\Forms;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\BaseElement;
use Exception;
use S2Hub\TextAssistant\Controllers\TranslationAdmin;
use S2Hub\TextAssistant\Extensions\FormFieldExtension;
use S2Hub\TextAssistant\Jobs\QueuePageTranslationsJob;
use S2Hub\TextAssistant\ORM\SiteConfigTranslationRelation;
use S2Hub\TextAssistant\ORM\SiteTreeTranslationRelation;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Security\Security;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CompositeField;
use TractorCow\Fluent\State\FluentState;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\ORM\ValidationResult;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;

class BatchActions_TranslateForm
{

    private $leftAndMain;
    protected $pjaxFragment = 'GridFieldDialogForm';

    public function __construct(LeftAndMain $leftAndMain = null)
    {
        $this->leftAndMain = $leftAndMain;
    }

    public function getForm(HTTPRequest $request = null)
    {
        $fields = new FieldList();
        $actions = new FieldList();

        $localeObjs = Locale::getLocales();
        $locales = [];

        foreach ($localeObjs as $locale) {
            $locales[$locale->Locale] = $locale->Title;
        }

        $currentLocale = FluentState::singleton()->getLocale();

        $initialTranslateFrom = array_key_first($locales);

        if ($initialTranslateFrom === $currentLocale) {
            next($locales);
            $initialTranslateFrom = key($locales);

            if (empty($initialTranslateFrom)) {
                $initialTranslateFrom = array_key_first($locales);
            }
        }

        $fields->push(new LiteralField('translate-option-wrapper-start', '<div id="translate-option-wrapper">'));

        $fields->push(DropdownField::create('TranslateFrom', _t(self::class.'.TRANSLATEFROM', 'Translate from'), $locales, $initialTranslateFrom)->addExtraClass('no-change-track'));
        $fields->push(DropdownField::create('TranslateTo', _t(self::class.'.TRANSLATETO', 'Translate to'), $locales, $currentLocale)->addExtraClass('no-change-track'));
        
        $fields->push(new LiteralField('translate-option-wrapper-start', '</div>'));

        $this->getFieldsForRequest($fields, $request);

        $actions->push(FormAction::create('StartTranslation', _t(self::class.'.STARTTRANSLATION', 'Start translation'))->setUseButtonTag(true)->addExtraClass('btn btn-primary'));
        $actions->push(FormAction::create('close', _t(FormFieldExtension::class.'.CLOSE', 'Close'))->setUseButtonTag(true)->addExtraClass('btn btn-secondary'));

        if (strpos($request->getURL(true), 'batchactions_translateform_action') !== false) {
            $formAction = $request->getURL(true);
        } else {
            $formAction = str_replace("batchactions_translateform", "batchactions_translateform_action", $request->getURL(true));
        }


        $form = Form::create($this->leftAndMain, "batchactions_translateform", $fields, $actions)
            ->setFormAction($formAction)
            ->setTemplate([
                'S2Hub\\TextAssistant\\Forms\\BatchActions_TranslateForm',
            ])
            ->addExtraClass('cms-content cms-edit-form center fill-height flexbox-area-grow batchactions_translateform')
            ->setAttribute('data-pjax-fragment', $this->getPjaxFragment($request));

        if ($form->Fields()->hasTabSet()) {
            $form->Fields()->findOrMakeTab('Root')->setTemplate('SilverStripe\\Forms\\CMSTabSet');
            $form->addExtraClass('cms-tabset');
        
        }

        if ($this->leftAndMain->getRequest()->isGET()) {
            return $form->forTemplate();
        }

        return $form;
    }

    public function getAfterStartedForm(HTTPRequest $request = null)
    {
        $form = $this->getForm($request);

        $fields = new FieldList();

        $fields->push(new LiteralField('TranslationStarted', "<div class='translation-started'>"._t(self::class.'.STARTED', 'Translation started')."</div>"));
        
        $form = $this->getForm($request)
            ->setActions(new FieldList([
                FormAction::create('close', _t(FormFieldExtension::class.'.CLOSE', 'Close'))->setUseButtonTag(true)->addExtraClass('btn btn-primary')
            ]))
            ->setFields($fields);

        return $form;
    }

    public function StartTranslation(HTTPRequest $request = null)
    {
        $ids = $request->requestVar('ids');
        $fromLocale = $request->requestVar('TranslateFrom');
        $toLocale = $request->requestVar('TranslateTo');
        $validationResult = new ValidationResult();

        if (empty($fromLocale)) throw new Exception("No from locale given");
        if (empty($toLocale)) throw new Exception("No to locale given");


        if (empty($ids)) {
            $validationResult->addError(_t(self::class.'.NOIDS', 'No pages selected'), ValidationResult::TYPE_ERROR);
        }

        if ($fromLocale === $toLocale) {
            $validationResult->addError(_t(self::class.'.SAMELOCALE', 'Cannot translate to the same locale'), ValidationResult::TYPE_ERROR);
            
        }

        if (!$validationResult->isValid()) {
            $form = $this->getForm($request);

            $messageString = "";

            foreach ($validationResult->getMessages() as $message) {
                $messageString .= "&#x2022; " . $message['message'] . "<br/>";

            }

            $form->setMessage($messageString, ValidationResult::TYPE_ERROR, ValidationResult::CAST_HTML);

            $form = $form->loadDataFrom($request->postVars());

            return $form->forTemplate();
        }

        $ids = explode(',', $ids);
        $group = uniqid("", true);

        $options = $request->requestVar('Options');

        $job = new QueuePageTranslationsJob();
        $jobData = new \stdClass();
        $jobData->fromLocale = $fromLocale;
        $jobData->toLocale = $toLocale;
        $jobData->ids = $ids;
        $jobData->options = $options;
        $jobData->group = $group;
        $jobData->CreatorID = Security::getCurrentUser()->ID;

        $job->setJobData(0, 0, false, $jobData, []);
        $descriptorID = QueuedJobService::singleton()->queueJob($job, null, null, QueuedJob::IMMEDIATE);

        $response = Controller::curr()->getResponse();
        $response->addHeader('X-Close', true);
        $response->addHeader("X-Redirect", $this->leftAndMain->Link(TranslationAdmin::config()->url_segment));
        return $response;
    }

    private function getFieldsForRequest(FieldList $fields, HTTPRequest $request = null)
    {
        $ids = $request->requestVar('ids');

        if (empty($ids)) {
            return;
        }

        $ids = explode(',', $ids);

        $pages = DataList::create(SiteTree::class)
            ->filter([
                'ID' => $ids,
                'ParentID' => 0
            ])
            ->sort('Sort', 'ASC');

        if ($pages->count() === 0) {
            $pages = DataList::create(SiteTree::class)
                ->filter([
                    'ID' => $ids,
                ])
                ->sort('Sort', 'ASC');
        }

        foreach ($pages as $page) {
            $compositeField = $this->getCompositeFieldForPage($page, $ids);

            $fields->push($compositeField);
        }
    }

    private function getCompositeFieldForPage(SiteTree $page, array $allowedIds): CompositeField
    {
        $fields = new FieldList();
        
        if ($page->getPageIconURL())    $pageIcon = '<img src='.$page->getPageIconURL().' />';
        else if ($page->getIconClass()) $pageIcon = '<div class="'.$page->getIconClass().' page-icon"></div>';
        else $pageIcon = "";

        $fields->push(new LiteralField('Page_Title', "<div class='page-title'><span class='icon'>". $pageIcon . "</span><span class='title'>" . $page->Title . "</span></div>"));

        $optionFields = new FieldList();
        $options = $this->getTranslationRelationsForPage($page);

        foreach ($options as $option) {
            $optionFields->push(CheckboxField::create('Options[' . $page->ID . "][" . $option->getObjectType() . "_" . $option->getName() . "]", $option->getNiceName(), $option->isCheckedByDefault())
                ->addExtraClass('no-change-track'));
        }

        $compositeOptions = CompositeField::create($optionFields)
            ->setName("Page_" . $page->ID . "_Options")
            ->addExtraClass('options-container');
        $fields->push($compositeOptions);

        foreach ($page->stageChildren(true) as $child) {
            if (!in_array($child->ID, $allowedIds)) continue;

            $fields->push($this->getCompositeFieldForPage($child, $allowedIds)->addExtraClass('indent'));

        }

        return CompositeField::create($fields)
            ->setName("Page_" . $page->ID)
            ->addExtraClass('page-container');
    }

    public static function getTranslationRelationsForPage(SiteTree $page): ArrayList
    {
        $options = new ArrayList();
        $controller = ModelAsController::controller_for($page, null);
        
        // If has blocks, add checkbox for translate those
        $options->merge(self::getPageOptions($page, $controller));

        if ($page->hasMethod('addTranslationOptions')) {
            $options->merge($page->addTranslationOptions());
        }

        if ($page->URLSegment == 'home' && SiteConfig::current_site_config()->hasExtension(FluentExtension::class)) {
            $siteConfig = SiteConfig::current_site_config();

            $options->push(new SiteConfigTranslationRelation('SiteConfig', $siteConfig, !$siteConfig->existsInLocale()));

            if ($siteConfig->hasMethod('addTranslationOptions')) {
                $options->merge($siteConfig->addTranslationOptions());
            }
        }

        return $options;
    }

    private static function getPageOptions(SiteTree $page, Controller $controller): ArrayList
    {
        $options = new ArrayList();

        $elementalAreas = [];
        if ($page->hasExtension(ElementalPageExtension::class)) {
            $elementalAreas = [$page->ElementalArea()];
        }

        if (!empty($elementalAreas)) {
            $elementIDs = [];

            foreach ($elementalAreas as $area) {
                $elementIDs = array_merge($elementIDs, $area->Elements()->column('ID'));
            }

            if (!empty($elementIDs)) {
                $options->push(new SiteTreeTranslationRelation('Blocks', BaseElement::get()->byIDs($elementIDs), true));
            }

        }
        
        return $options;
    }

    protected function getPjaxFragment($request = null) {
		if ($request) {
			if ($request->getHeader('X-Pjax')) {
				$this->pjaxFragment = $request->getHeader('X-Pjax');
			}
		}
		return $this->pjaxFragment;
	}
}