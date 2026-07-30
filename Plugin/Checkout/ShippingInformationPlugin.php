<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;

/**
 * Persists the checkout suburb onto the quote address.
 *
 * This is the step that makes the whole suburb feature work, and its absence
 * was invisible for a long time because every other link in the chain existed:
 *
 *   LayoutProcessorPlugin  renders the field  -> shippingAddress.custom_attributes.suburb
 *   set-shipping-information-mixin.js         -> extension_attributes.suburb on the request
 *   >>> THIS PLUGIN <<<                       -> quote_address.suburb            (the gap)
 *   ToOrderAddressPlugin                      -> sales_order_address.suburb
 *   OrderMapper                               -> local_area on the Bob Go payload
 *
 * Magento does not persist extension attributes on its own — an extension
 * attribute exists only for the lifetime of the request that carried it. Order
 * placement is a *separate* request from set-shipping-information, so by the
 * time ToOrderAddress runs, the quote address has been reloaded from a database
 * that never stored the suburb, and all of its lookups come back empty. The
 * suburb then silently fell back to the city on every outbound order.
 *
 * Rate requests were never affected, which is why this went unnoticed:
 * AdditionalInfo reads the suburb straight out of the request body, so the
 * rate was correct while the order that followed it was not.
 *
 * Writing a plain data key is what makes it stick: Quote::setShippingAddress()
 * merges the incoming address with `$old->addData($address->getData())`, and
 * `quote_address.suburb` is a real column (see etc/db_schema.xml), so the value
 * survives to the row.
 */
class ShippingInformationPlugin
{
    /**
     * @param ShippingInformationManagement $subject
     * @param mixed $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return null Arguments are mutated in place; nothing to rewrite.
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $address = $addressInformation->getShippingAddress();
        if ($address === null) {
            return null;
        }

        $suburb = $this->extractSuburb($address);
        if ($suburb !== '') {
            $address->setData('suburb', $suburb);
        }

        return null;
    }

    /**
     * The mixin normalises three different custom-attribute shapes into
     * extension_attributes, so that is the expected source. The custom-attribute
     * fallback covers a checkout that posts the field without the mixin — a
     * third-party one-page checkout, say — since dropping the suburb silently
     * is exactly the failure this plugin exists to end.
     */
    private function extractSuburb(QuoteAddressInterface $address): string
    {
        $ext = $address->getExtensionAttributes();
        if ($ext !== null && method_exists($ext, 'getSuburb')) {
            $value = $ext->getSuburb();
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        if (method_exists($address, 'getCustomAttribute')) {
            $attr = $address->getCustomAttribute('suburb');
            if ($attr !== null && is_scalar($attr->getValue()) && trim((string) $attr->getValue()) !== '') {
                return trim((string) $attr->getValue());
            }
        }

        return '';
    }
}
