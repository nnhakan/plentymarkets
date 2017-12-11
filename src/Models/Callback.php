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

namespace Novalnet\Models;
use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class Callback
 *
 * @property int        $id
 * @property int        $orderNo
 * @property int        $amount
 * @property int        $callbackAmount
 * @property string     $referenceTid
 * @property string     $callbackDatetime
 * @property string     $tid
 * @property string     $paymentName
 */
class Callback extends Model
{
    public $id;
    public $orderNo;
    public $amount;
    public $callbackAmount;
    public $referenceTid;
    public $callbackDatetime;
    public $tid;
    public $paymentName;

    /**
     * Get callback table name
     *
     * @return string
     */
    public function getTableName(): string
    {
        return 'Novalnet::Callback';
    }
}
