<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet
 * @copyright(C) Novalnet. All rights reserved. <https://www.novalnet.de/>
 */

namespace Novalnet\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\Callback;
use Plenty\Plugin\Log\Loggable;

/**
 * Class CallbackService
 *
 * @package Novalnet\Services
 */
class CallbackService
{

    use Loggable;

    /**
     * Save data in callback table
     *
     * @param $data
     */
    public function saveCallback($data)
    {
        try {
            $database = pluginApp(DataBase::class);
            $callback = pluginApp(Callback::class);
            $callback->orderNo = $data['order_no'];
            $callback->amount = $data['amount'];
            $callback->callbackAmount = $data['callback_amount'];
            $callback->referenceTid = $data['ref_tid'];
            $callback->callbackDatetime = date('Y-m-d H:i:s');
            $callback->tid = $data['tid'];
            $callback->paymentName = $data['payment_name'];

            $database->save($callback);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Callback table insert failed!.', $e);
        }
    }


    /**
     * get callback table data
     *
     * @param string $key
     * @param mixed $value
     * @return array
     */
    public function getCallback($key,$value)
    {
        $database = pluginApp(DataBase::class);
        $order = $database->query(Callback::class)->where($key, '=', $value)->get();
        return $order;
    }

}
