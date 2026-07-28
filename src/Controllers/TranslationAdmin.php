<?php

namespace S2Hub\TextAssistant\Controllers;

use Colymba\BulkManager\BulkManager;
use S2Hub\TextAssistant\Forms\GridField\BulkManager\TranslationActionContainerObjectPublishHandler;
use S2Hub\TextAssistant\Forms\GridField\BulkManager\TranslationActionContainerObjectSendToProofReader;
use S2Hub\TextAssistant\Forms\GridField\TranslationActionContainerObjectItemRequest;
use S2Hub\TextAssistant\Models\TranslationAction;
use S2Hub\TextAssistant\Models\TranslateFilter;
use S2Hub\TextAssistant\Models\TextAssistantSettings;
use S2Hub\TextAssistant\ORM\TranslationActionDataList;
use S2Hub\TextAssistant\Forms\GridField\TranslationAdminInstructionsButton;
use S2Hub\TextAssistant\Forms\TranslationAdminInformationField;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;

class TranslationAdmin extends ModelAdmin implements PermissionProvider
{
    private static $url_segment = 'translations';

    private static $menu_title = 'Translations';

    private static $menu_priority = 1;

    private static $menu_icon_class = 'font-icon-globe';

    private static $managed_models = [
        TranslationAction::class,
        TranslateFilter::class,
        TextAssistantSettings::class
    ];

    private static $required_permission_codes = 'CMS_ACCESS_TranslationAdmin';

    public $showImportForm = false;

    public $showSearchForm = false;

    protected function getManagedModelTabs()
    {
        $forms = parent::getManagedModelTabs();
        $member = Security::getCurrentUser();

        if (!Permission::checkMember($member, "EDIT_TEXTASSISTANT_SETTINGS")) {
            $forms = $forms->exclude([
                'Tab' => TextAssistantSettings::class,
            ]);
        }
        if (!Permission::checkMember($member, "EDIT_TRANSLATION_FILTERS")) {
            $forms = $forms->exclude([
                'Tab' => TranslateFilter::class,
            ]);
        }

        return $forms;
    }


    public function getList()
    {
        $list = parent::getList();

        if ($this->modelClass == TranslationAction::class) {

            $list = TranslationActionDataList::create()
                ->filter('Status', 'Draft')
                ->sort('Created', 'DESC');

            if (($member = Security::getCurrentUser())
                && !Permission::checkMember($member, 'ADMIN')
                && ($locales = $member->ProofreadingLocales())
                && $locales->exists()
            ) {
                $list = $list->filter([
                    'Locale' => $locales->column('Locale'),
                ]);
            }
        }

        return $list;
    }

    public function getEditForm($id = null, $fields = null)
    {
        if ($this->modelTab == TextAssistantSettings::class) {
            $form = Form::create();

            if (($member = Security::getCurrentUser())
                && Permission::checkMember($member, "EDIT_TEXTASSISTANT_SETTINGS")
            ) {
                $record = TextAssistantSettings::currentRecord();
                $form = Form::create(
                    $this,
                    'EditForm',
                    $record->getCMSFields(),
                    new FieldList([
                        FormAction::create('SaveTextAssistantSettings', _t('SilverStripe\\Admin\\ModelAdmin.SAVE', 'Save'))
                            ->setUseButtonTag(true)
                            ->addExtraClass('btn btn-primary font-icon-save')
                    ])
                )->setHTMLID('Form_EditForm');
                $form->addExtraClass('cms-edit-form cms-panel-padded center flexbox-area-grow');
                $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
                $editFormAction = Controller::join_links($this->getLinkForModelTab($this->modelTab), 'EditForm');
                $form->setFormAction($editFormAction);
                $form->setAttribute('data-pjax-fragment', 'CurrentForm');
                $form->loadDataFrom($record);

                // Check if the the record  requires sudo mode, If so then require sudo mode for the edit form
                if ($record->getRequireSudoMode()) {
                    $form->requireSudoMode();
                }

                $this->extend('updateEditForm', $form);
            }

            return $form;
        }
        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass == TranslationAction::class) {
            $form->addExtraClass('TranslationAdmin');

            $gridField = $form->Fields()->fieldByName($this->sanitiseClassName($this->modelClass));
            $config = $gridField->getConfig();

            $config
                ->removeComponentsByType(GridFieldExportButton::class)
                ->removeComponentsByType(GridFieldPrintButton::class);

            $form->Fields()->insertBefore(
                $this->sanitiseClassName($this->modelClass),
                TranslationAdminInformationField::create($gridField->getList())->setForm($form)
            );

            $columns = $config->getComponentByType(GridFieldDataColumns::class);

            $config->getComponentByType(GridFieldDetailForm::class)
                ->setItemRequestClass(TranslationActionContainerObjectItemRequest::class);


            $columns->setDisplayFields([
                'ObjectType' => _t(TranslationAction::class.'.TYPE', 'Type'),
                'ObjectDescription' => _t(TranslationAction::class.'.NAME', 'Name'),
                'ActionFromToLocale' => _t(TranslationAction::class.'.LOCALE', 'Locale'),
                'CreatedNice' => _t(TranslationAction::class.'.SINGULARNAME_ADJECTIVE', 'Translated')
            ]);

            $bulkManager = new BulkManager(false, false, false);

            $bulkManager->addBulkAction(TranslationActionContainerObjectPublishHandler::class);
            if (Permission::check(["ADMIN", "SITETREE_EDIT_ALL"])) {
                $bulkManager->addBulkAction(TranslationActionContainerObjectSendToProofReader::class);
            }

            $config->addComponent($bulkManager);

            if (Permission::check(["ADMIN", "SITETREE_EDIT_ALL"])) {
                $config->addComponent(new TranslationAdminInstructionsButton());
            }
        }

        if ($this->modelClass == TranslateFilter::class) {
            if (($member = Security::getCurrentUser())
                && !Permission::checkMember($member, "EDIT_TRANSLATION_FILTERS")
            ) {
                $form = Form::create();
            }
        }

        return $form;
    }

    public function SaveTextAssistantSettings($data, Form $form)
    {
        $record = TextAssistantSettings::currentRecord();
        $form->saveInto($record);
        $record->write();

        $this->getResponse()->addHeader('X-Status', rawurlencode(_t('SilverStripe\\Admin\\LeftAndMain.SAVEDUP', 'Saved.')));

        return $this->redirectBack();
    }

    public function providePermissions()
    {
        $title = $this->menu_title();
        return [
            "CMS_ACCESS_TranslationAdmin" => [
                'name' => _t(
                    LeftAndMain::class . '.ACCESS',
                    "Access to '{title}' section",
                    ['title' => $title]
                ),
                'category' => _t(LeftAndMain::class . '.CMS_ACCESS_CATEGORY', 'CMS Access'),
                'help' => _t(
                    __CLASS__ . '.ACCESS_HELP',
                    'Allow approving and denying of AI translations.'
                ),
            ],
            'EDIT_TRANSLATION_FILTERS' => [
                'name' => _t(__CLASS__ . '.EDITTRANSLATIONSFILTERS', 'Manage AI translation filters'),
                'category' => _t(
                    __CLASS__ . '.EDITTRANSLATIONS_CATEGORY',
                    'Translation permissions'
                ),
                'help' => _t(
                    __CLASS__ . '.EDITTRANSLATIONSFILTERS_HELP',
                    'Ability to edit filters for AI translations.'
                    . ' Requires the "Access to \'Translations\' section" permission.'
                ),
                'sort' => 0,
            ],
            'EDIT_TEXTASSISTANT_SETTINGS' => [
                'name' => _t(__CLASS__ . '.EDITTEXTASSISTANTSETTINGS', 'Manage AI text assistant settings'),
                'category' => _t(
                    __CLASS__ . '.EDITTRANSLATIONS_CATEGORY',
                    'Translation permissions'
                ),
                'help' => _t(
                    __CLASS__ . '.EDITTEXTASSISTANTSETTINGS_HELP',
                    'Ability to edit the settings of the AI text assistant.'
                    . ' Requires the "Access to \'Translations\' section" permission.'
                ),
                'sort' => 0,
            ],
        ];
    }
}
