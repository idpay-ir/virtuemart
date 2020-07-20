<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://idpay.ir
 */

defined('_JEXEC') or die();
?>

<div id="idpay_result">
    <?php echo $viewData['status']; ?>
</div>
<a class="vm-button-correct"
   href="<?php echo JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $viewData["order_number"] . '&order_pass=' . $viewData["order_pass"], false) ?>"><?php echo vmText::_('مشاهده سفارش'); ?></a>