<?php
declare(strict_types=1);

namespace Folix\AttributeBatchImport\Block\Adminhtml\Attribute\Edit\Options;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;


/**
 * Renders the Batch Import section below Manage Options on the attribute edit form.
 *
 * Follows the same pattern as Magento\Swatches\Block\Adminhtml\Attribute\Edit\Options\Text
 * and \Visual: a Template block placed in the "main" container via layout XML.
 */
class BatchImport extends Template
{
    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_template = 'Folix_AttributeBatchImport::batch-import.phtml';
    }
}
