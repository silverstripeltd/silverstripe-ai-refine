<?php

namespace SilverstripeLtd\AiRefine\Forms;

use SilverstripeLtd\AiRefine\Controllers\RefineController;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\HTML;

/**
 * Builds the server-side schema for the on-demand Refine modal.
 */
class RefineCheckForm extends Form
{
    public const FORM_NAME_TEMPLATE = 'RefineCheckForm_%s';
    public const MODAL_TITLE = 'Refine page content with AI';
    public const DRAFT_NOTICE = 'This check evaluates your saved draft content. Save the page to draft before'
        . ' checking if you have unsaved changes.';
    public const EMPTY_STATE_MESSAGE = 'Click the button below to check the content on this page against'
        . ' your writing style and tone rules.';
    public const ALL_ALIGNED_MESSAGE = 'Your content already matches the refine rules. No changes needed.';
    public const CHECK_BUTTON_LABEL = 'Refine';
    public const RECHECK_BUTTON_LABEL = 'Regenerate';
    public const APPLY_BUTTON_LABEL = 'Apply changes';
    public const APPLY_SUGGESTION_LABEL = 'Apply this suggestion';
    public const CHECK_SUCCESS_MESSAGE = 'Refine check complete';
    public const CHECK_FAILURE_MESSAGE = 'Refine check failed';
    public const APPLY_SUCCESS_MESSAGE = 'Refine suggestions applied to draft content';
    public const APPLY_PARTIAL_MESSAGE = 'Some suggestions could not be applied';
    public const APPLY_FAILURE_MESSAGE = 'Unable to apply refine suggestions';
    public const COPY_BUTTON_LABEL = 'Copy to clipboard';
    public const COPY_SUCCESS_MESSAGE = 'Copied to clipboard';
    public const COPY_FAILURE_MESSAGE = 'Unable to copy to clipboard';
    public const NO_CONTENT_MESSAGE = 'This page has no content to evaluate';
    public const PROVIDER_ERROR_MESSAGE = 'There was an error connecting to the AI provider';
    public const RATING_LABEL = 'Refine rating';
    public const REASONING_LABEL = 'Reasoning summary';
    public const REWRITE_LABEL = 'Suggested rewrite';
    public const REASONING_ROWS = 5;
    public const REWRITE_ROWS = 18;

    /**
     * Creates the modal form schema for a specific CMS record.
     */
    public static function createForRecord(
        RefineController $controller,
        DataObject $record,
        bool $refineConfigured
    ): self {
        $fields = FieldList::create(
            LiteralField::create(
                'RefineDraftNotice',
                self::renderBanner(self::DRAFT_NOTICE, 'info')
            ),
            LiteralField::create(
                'RefineEmptyState',
                HTML::createTag(
                    'p',
                    ['class' => 'ai-refine-modal__empty-state'],
                    Convert::raw2xml(self::EMPTY_STATE_MESSAGE)
                )
            ),
            ReadonlyField::create('RatingDisplay', self::RATING_LABEL, '')
                ->addExtraClass('ai-refine-modal__rating-field'),
            TextareaField::create('ReasoningSummaryDisplay', self::REASONING_LABEL)
                ->setRows(self::REASONING_ROWS)
                ->setAttribute('readonly', 'readonly')
                ->addExtraClass('ai-refine-modal__reasoning-field'),
            TextareaField::create('RewrittenContentDisplay', self::REWRITE_LABEL)
                ->setRows(self::REWRITE_ROWS)
                ->setAttribute('readonly', 'readonly')
                ->addExtraClass('ai-refine-modal__rewrite-field'),
            LiteralField::create(
                'RefineCopyAffordance',
                HTML::createTag(
                    'p',
                    ['class' => 'ai-refine-modal__copy-affordance'],
                    Convert::raw2xml(self::COPY_BUTTON_LABEL)
                )
            ),
            HiddenField::create('RefineConfigured', '', $refineConfigured ? '1' : '0')
        );

        $actions = FieldList::create(
            FormAction::create('RefineCheckAction', self::CHECK_BUTTON_LABEL)
                ->setAttribute('type', 'button')
                ->setAttribute('data-schema-only', 'true')
        );

        /** @var self $form */
        $form = self::create(
            $controller,
            sprintf(self::FORM_NAME_TEMPLATE, $record->ID),
            $fields,
            $actions
        );
        $form->setFormAction($controller->Link(sprintf(
            'check/%d?fqcn=%s',
            $record->ID,
            rawurlencode($record->ClassName)
        )));
        $form->addExtraClass('form--no-dividers ai-refine-modal__schema');
        $form->loadDataFrom([
            'RatingDisplay' => '',
            'ReasoningSummaryDisplay' => '',
            'RewrittenContentDisplay' => '',
            'RefineConfigured' => $refineConfigured ? '1' : '0',
        ]);
        return $form;
    }

    /**
     * Renders a modal banner for draft notices and inline status messages.
     */
    private static function renderBanner(string $message, string $variant): string
    {
        return HTML::createTag(
            'div',
            ['class' => sprintf('ai-refine-modal__banner ai-refine-modal__banner--%s', $variant)],
            Convert::raw2xml($message)
        );
    }
}
