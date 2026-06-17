<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\FormSubmissionRecord;

/**
 * Delivers a stored form submission to its configured destinations. Throwing on
 * failure lets the Craft queue retry, so a momentarily-down endpoint recovers.
 */
class SendFormJob extends BaseJob
{
    public int $submissionId;

    public function execute($queue): void
    {
        $rec = FormSubmissionRecord::findOne($this->submissionId);
        if (!$rec) {
            return; // submission was deleted; nothing to do
        }
        Plugin::getInstance()->forms->deliver($rec);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Delivering form submission #{id}', ['id' => $this->submissionId]);
    }
}
