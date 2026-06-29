<?php

namespace S2Hub\TextAssistant\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Permission;
use TractorCow\Fluent\Model\Locale;

class MemberExtension extends Extension
{
    private static $belongs_many_many = [
        'ProofreadingLocales' => Locale::class,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'ProofreadingLocales',
        ]);
        if (Permission::check("EDIT_PERMISSIONS")
            && Permission::checkMember($this->getOwner(), "CMS_ACCESS_TranslationAdmin")
        ) {
            $fields->addFieldsToTab(
                'Root.AITranslations',
                [
                    CheckboxSetField::create(
                        'ProofreadingLocales',
                        _t(__CLASS__ . '.PROOFREADINGLOCALES', 'AI translations allowed to approve'),
                        Locale::get()
                    )
                ]
            );
            $tab = $fields->fieldByName('Root.AITranslations');
            if ($tab) {
                $tab->setTitle(
                    _t(__CLASS__ . '.AITRANSLATIONSTAB', 'Translation Permissions'),
                );
            }
        }
    }
}
