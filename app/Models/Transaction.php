<?php

namespace App\Models;

use App\Helper\Format;
use App\Services\Payme\PaycomException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected  $table = 'payme_transactions';

    /** Transaction expiration time in milliseconds. 43 200 000 ms = 12 hours. */
    const TIMEOUT = 43200000;
    const STATE_CREATED                  = 1;
    const STATE_COMPLETED                = 2;
    const STATE_CANCELLED                = -1;
    const STATE_CANCELLED_AFTER_COMPLETE = -2;

    const REASON_RECEIVERS_NOT_FOUND         = 1;
    const REASON_PROCESSING_EXECUTION_FAILED = 2;
    const REASON_EXECUTION_FAILED            = 3;
    const REASON_CANCELLED_BY_TIMEOUT        = 4;
    const REASON_FUND_RETURNED               = 5;
    const REASON_UNKNOWN                     = 10;

    /** @var string Paycom transaction id. */
    public $paycom_transaction_id;

    /** @var int Paycom transaction time as is without change. */
    public $paycom_time;

    /** @var string Paycom transaction time as date and time string. */
    public $paycom_time_datetime;

    /** @var int Transaction id in the merchant's system. */
    public $id;

    /** @var string Transaction create date and time in the merchant's system. */
    public $create_time;

    /** @var string Transaction perform date and time in the merchant's system. */
    public $perform_time;

    /** @var string Transaction cancel date and time in the merchant's system. */
    public $cancel_time;

    /** @var int Transaction state. */
    public $state;

    /** @var int Transaction cancelling reason. */
    public $reason;

    /** @var int Amount value in coins, this is service or product price. */
    public $amount;

    /** @var string Pay receivers. Null - owner is the only receiver. */
    public $receivers;

    // additional fields:
    // - to identify order or product, for example, code of the order
    // - to identify client, for example, account id or phone number

    /** @var string Code to identify the order or service for pay. */
    public $order_id;

    /**
     * Saves current transaction instance in a data store.
     * @return bool true - on success
     */
    public function payme_create_transaction($data)
    {
        // todo: Implement creating/updating transaction into data store
        // todo: Populate $id property with newly created transaction id

        // Example implementation

         self::create([

         ]);

        if (!$this->id) {

            // Create a new transaction

            $this->create_time = Format::timestamp2datetime(Format::timestamp());
            $sql               = "INSERT INTO transactions SET
                                    paycom_transaction_id = :pPaycomTxId,
                                    paycom_time = :pPaycomTime,
                                    paycom_time_datetime = :pPaycomTimeStr,
                                    create_time = :pCreateTime,
                                    amount = :pAmount,
                                    state = :pState,
                                    receivers = :pReceivers,
                                    order_id = :pOrderId";

            $sth = $db->prepare($sql);

            $is_success = $sth->execute([
                ':pPaycomTxId'    => $this->paycom_transaction_id,
                ':pPaycomTime'    => $this->paycom_time,
                ':pPaycomTimeStr' => $this->paycom_time_datetime,
                ':pCreateTime'    => $this->create_time,
                ':pAmount'        => 1 * $this->amount,
                ':pState'         => $this->state,
                ':pReceivers'     => $this->receivers ? json_encode($this->receivers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':pOrderId'       => 1 * $this->order_id,
            ]);

            if ($is_success) {
                // set the newly inserted transaction id
                $this->id = $db->lastInsertId();
            }
        } else {

            // Update an existing transaction by id

            $sql = "UPDATE transactions SET
                    perform_time = :pPerformTime,
                    cancel_time = :pCancelTime,
                    state = :pState,
                    reason = :pReason";

            $params = [];

            if ($this->amount) {
                $sql                .= ", amount = :pAmount";
                $params[':pAmount'] = 1 * $this->amount;
            }

            $sql .= " where paycom_transaction_id = :pPaycomTxId and id=:pId";

            $sth = $db->prepare($sql);

            $perform_time = $this->perform_time ? $this->perform_time : null;
            $cancel_time  = $this->cancel_time ? $this->cancel_time : null;
            $reason       = $this->reason ? 1 * $this->reason : null;

            $params[':pPerformTime'] = $perform_time;
            $params[':pCancelTime']  = $cancel_time;
            $params[':pState']       = 1 * $this->state;
            $params[':pReason']      = $reason;
            $params[':pPaycomTxId']  = $this->paycom_transaction_id;
            $params[':pId']          = $this->id;

            $is_success = $sth->execute($params);
        }

        return $is_success;
    }

    /**
     * Cancels transaction with the specified reason.
     * @param int $reason cancelling reason.
     * @return void
     */
    public function cancel($reason)
    {
        $this->cancel_time = Format::timestamp2datetime(Format::timestamp());
        if ($this->state == self::STATE_COMPLETED) {
            $this->state = self::STATE_CANCELLED_AFTER_COMPLETE;
        } else {
            $this->state = self::STATE_CANCELLED;
        }
        $this->reason = $reason;
        $this->save();
    }

    /**
     * Determines whether current transaction is expired or not.
     * @return bool true - if current instance of the transaction is expired, false - otherwise.
     */
    public function isExpired(): bool
    {
        return $this->state == self::STATE_CREATED && abs(Format::datetime2timestamp($this->create_time) - Format::timestamp(true)) > self::TIMEOUT;
    }

    /**
     * Find transaction by given parameters.
     * @param mixed $params parameters
     * @return Transaction|Transaction[]
     * @throws PaycomException invalid parameters specified
     */
    public function find_by($params): array|Transaction
    {
        if(isset($params['id'])){
            if(!empty($transaction  = self::where('paycom_transaction_id', $params['id'])->first())){
                return $transaction;
            }
        }

        if(isset($params['account']['order_id'])) {
            if(!empty($transaction  = self::where('order_id', $params['account']['order_id'])->first())){
                    return $transaction;
            }
        }


        throw new PaycomException(
            $params['request_id'],
            'Parameter to find a transaction is not specified.',
            PaycomException::ERROR_INTERNAL_SYSTEM
        );

    }

    /**
     * Gets list of transactions for the given period including period boundaries.
     * @param int $from_date start of the period in timestamp.
     * @param int $to_date end of the period in timestamp.
     * @return array list of found transactions converted into report format for send as a response.
     */
    public function report($from_date, $to_date): array
    {
        $from_date = Format::timestamp2datetime($from_date);
        $to_date   = Format::timestamp2datetime($to_date);
        $rows = self::whereBetween('paycom_time_datetime', [$from_date, $to_date])->orderBy('paycom_time_datetime')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'           =>  $row->paycom_transaction_id, // paycom transaction id
                'time'         => 1 * $row->paycom_time, // paycom transaction timestamp as is
                'amount'       => 1 * $row->amount,
                'account'      => [
                    'order_id' => 1 * $row->order_id, // account parameters to identify client/order/service
                ],
                'create_time'  => Format::datetime2timestamp($row->create_time),
                'perform_time' => Format::datetime2timestamp($row->perform_time),
                'cancel_time'  => Format::datetime2timestamp($row->cancel_time),
                'transaction'  => 1 * $row->id,
                'state'        => 1 * $row->state,
                'reason'       => isset($row->reason) ? 1 * $row->reason : null,
                'receivers'    => isset($row->receivers) ? json_decode($row->receivers, true) : null,
            ];
        }

        return $result;

    }
}
