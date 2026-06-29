<?php

namespace S2Hub\TextAssistant\Forms\GridField\BulkManager;

use Colymba\BulkManager\BulkAction\Handler;
use Colymba\BulkTools\HTTPBulkToolsResponse;
use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;
use TractorCow\Fluent\Model\Locale;


class TranslationActionContainerObjectSendToProofReader extends Handler
{

    private static $url_segment = 'send_to_proofreader';

    private static $allowed_actions = [
        'send_to_proofreader',
    ];

    private static $url_handlers = [
        '' => 'send_to_proofreader',
    ];

    protected $label = 'send_to_proofreader';

    public function getI18nLabel()
    {
        return _t(self::class.'.TITLE', "Send to proofreader");
    }

    public function send_to_proofreader(HTTPRequest $request)
    {
        $response = new HTTPBulkToolsResponse(false, $this->gridField);
        $records = $this->getRecords();

        $proofreadingRegister = [];

        foreach ($records as $record) {

            if ($record instanceof TranslationAction) {
                $actions = DataList::create(TranslationAction::class)
                    ->filter([
                        'ObjectClass' => $record->ObjectClass,
                        'ObjectID' => $record->ObjectID,
                        'Locale' => $record->Locale,
                    ]);
            } else {
                $actions = $record->getContainingObjects();
            }

            if ($actions && $actions->count() > 0) {
                $locale = $actions->first()->Locale;
                if (!array_key_exists($locale, $proofreadingRegister)) {
                    $proofreadingRegister[$locale] = 1;
                } else {
                    $proofreadingRegister[$locale] += 1;
                }
            }

        }

        $success = [];
        $failed = [];
        foreach ($proofreadingRegister as $locale => $count) {
            $localeSuccess = false;
            $localeTitle = Locale::get()->find('Locale', $locale)->Title;

            $members = Member::get()->filter([
                'ProofreadingLocales.Locale' => $locale,
            ]);
            foreach ($members as $member) {

                if ($member
                    && $member->exists()
                    && $member->Email
                    && Permission::checkMember($member, 'CMS_ACCESS_TranslationAdmin')
                ) {
                    $localeSuccess = $this->sendEmailToProofreader($member, $localeTitle, $count) || $localeSuccess;
                }
            }

            if ($localeSuccess) {
                $success[] = $localeTitle;
            } else {
                $failed[] = $localeTitle;
            }

        }

        $message = '';
        if (count($success) > 0) {
            $message = _t(self::class.'.SUCCESSMESSAGE', 'Successfully sent {Locales} to proofreader.', [
                'Locales' => implode(', ', $success),
            ]);
        }
        if (count($failed) > 0) {
            $message .= _t(self::class.'.FAILEDMESSAGE', ' {Locales} failed.', [
                'Locales' => implode(', ', $failed),
            ]);
        }

        $response->setMessage($message);

        return $response;
    }

    private function sendEmailToProofreader($member, $locale, $count)
    {
        $siteurl = str_replace(["http://", "https://", "/"], "", Director::absoluteBaseURL());
        $from = Config::inst()->get(Email::class, 'admin_email');
        $to = $member->Email;
        $subject = _t(
            self::class.'.SendEmailToProofreaderSubject',
            'Proofreading request: {count} new {locale} translations',
            [
                "count" => $count,
                "locale" => $locale,
            ]
        );
        $email = new Email($from, $to, $subject);

        $body = SSViewer::execute_template(
            'S2Hub\\TextAssistant\\Email\\ProofreaderEmailBody',
            ArrayData::create([
                'Subject' => $subject,
                'FirstName' => $member->FirstName,
                'Email' => $member->Email,
                'Locale' => $locale,
                'Count' => $count,
                'SiteUrl' => $siteurl,
                'AdminUrl' => Controller::join_links(Director::absoluteBaseURL(), 'admin', 'translations'),
            ])
        );
        $email->setBody($body);

        $result = false;
        try {
            $email->send();
            $result = true;
        } catch (\Throwable $e) {
        }
        return $result;
    }

}
