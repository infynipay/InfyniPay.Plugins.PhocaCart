<?php
/**
 * Infynipay plugin install/update script.
 *
 * Automatically creates (or corrects) the PhocaCart payment method row
 * so merchants don't have to configure type=1 manually.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;

class PlgPcpInfynipayInstallerScript extends InstallerScript
{
    public function install($parent): void
    {
        $this->ensurePaymentMethod();
    }

    public function update($parent): void
    {
        $this->ensurePaymentMethod();
    }

    private function ensurePaymentMethod(): void
    {
        $db = Factory::getDbo();

        // Check if the payment_methods table exists (PhocaCart may not be installed yet)
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $table  = $prefix . 'phocacart_payment_methods';

        if (!in_array($table, $tables, true)) {
            return;
        }

        // Check if our row already exists
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__phocacart_payment_methods'))
            ->where($db->quoteName('alias') . ' = ' . $db->quote('infynipay'));
        $db->setQuery($query);
        $existing = $db->loadResult();

        $imagePath = 'plugins/pcp/infynipay/images/logos/infynipay-logo-dark.png';

        if ($existing) {
            // Ensure type, published, and image are set correctly
            $db->setQuery(
                $db->getQuery(true)
                   ->update($db->quoteName('#__phocacart_payment_methods'))
                   ->set($db->quoteName('type') . ' = 1')
                   ->set($db->quoteName('published') . ' = 1')
                   ->set($db->quoteName('image') . ' = ' . $db->quote($imagePath))
                   ->where($db->quoteName('id') . ' = ' . (int) $existing)
            );
            $db->execute();
            return;
        }

        // Insert the payment method row with correct defaults
        $row = (object) [
            'title'       => 'Infynipay',
            'alias'       => 'infynipay',
            'method'      => 'infynipay',
            'type'        => 1,
            'published'   => 1,
            'ordering'    => 1,
            'default'     => 0,
            'tax_id'      => 0,
            'cost'        => '0.0000',
            'cost_additional' => '0.0000',
            'operator'    => '+',
            'calculation_type' => 0,
            'lowest_amount'    => '0.0000',
            'highest_amount'   => '0.0000',
            'active_amount'    => 0,
            'active_country'   => 0,
            'active_region'    => 0,
            'active_zone'      => 0,
            'active_shipping'  => 0,
            'active_currency'  => 0,
            'access'      => 1,
            'privacy'     => 0,
            'language'    => '*',
            'image'       => $imagePath,
            'params'      => '{}',
        ];

        $db->insertObject('#__phocacart_payment_methods', $row);
    }
}
