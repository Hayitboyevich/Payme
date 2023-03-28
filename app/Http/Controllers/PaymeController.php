<?php

namespace App\Http\Controllers;

use App\Helper\Format;
use App\Models\Transaction;
use App\Services\Payme\PaycomException;
use Illuminate\Http\Request;

class PaymeController extends Controller
{
    protected  $payme_requet = null;

    public function run(Request $request)
    {
        try {
             $this->payme_requet =  new \App\Services\Payme\Request();
            $this->Authorize($request);
            // handle request
            switch ($method = $request->input('method')) {
                case 'CheckPerformTransaction':
                    $this->CheckPerformTransaction($request);
                    break;
                case 'CheckTransaction':
                    $this->CheckTransaction($request);
                    break;
                case 'CreateTransaction':
                    $this->CreateTransaction($request);
                    break;
                case 'PerformTransaction':
                    $this->PerformTransaction($request);
                    break;
                case 'CancelTransaction':
                    $this->CancelTransaction($request);
                    break;
                case 'GetStatement':
                    $this->GetStatement($request);
                    break;
                default:
                    $this->payme_requet->error(
                        PaycomException::ERROR_METHOD_NOT_FOUND,
                        'Method not found.',
                             $method
                    );
                    break;
            }
        } catch (PaycomException $exc) {
            $exc->send();
        }
    }

    public function  CheckPerformTransaction(Request $request){
        /**
         * {
        "method" : "CheckPerformTransaction",
        "params" : {
            "amount" : 500000,
            "account" : {
                    "order_id" : 12312312,
             }
        }
        }
         */

        /**
         * {
            "result" : {
               "allow" : true,
                "additional": {
                        "Username ": "Juraqulni zakazi"
                }
            }
        }
         */

        $order = new Order($request->id);
        $order->find($request->params->account);

        // validate parameters
        $order->validate($request->params);

        $transaction = new Transaction();
        $found  = $transaction->find_by($request->params);
        if ($found && ($found->state == Transaction::STATE_CREATED || $found->state == Transaction::STATE_COMPLETED)) {
            $this->payme_requet->error(
                PaycomException::ERROR_COULD_NOT_PERFORM,
                'There is other active/completed transaction for this order.'
            );
        }
        response()->json(['allow' => true]);
    }

    public  function CreateTransaction(Request $request){

        $order = new Order($request->id);

        $order->find($request->params->account);

        // validate parameters
        $order->validate($request->params);

        $transaction = new Transaction();
        $found       = $transaction->find_by(['account' => $request->params->account]);

        if ($found) {
            if (($found->state == Transaction::STATE_CREATED || $found->state == Transaction::STATE_COMPLETED)
                && $found->paycom_transaction_id !== $request->params->id) {
                $this->payme_requet->error(
                    PaycomException::ERROR_INVALID_ACCOUNT,
                    'There is other active/completed transaction for this order.'
                );
            }
        }

        $transaction = new Transaction();
        $found       = $transaction->find_by($request->params);

        if ($found) {
            if ($found->state != Transaction::STATE_CREATED) { // validate transaction state
                $this->payme_requet->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Transaction found, but is not active.'
                );
            } elseif ($found->isExpired()) { // if transaction timed out, cancel it and send error
                $found->cancel(Transaction::REASON_CANCELLED_BY_TIMEOUT);
                $this->payme_requet->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Transaction is expired.'
                );
            } else { // if transaction found and active, send it as response
                return response()->json([
                    'create_time' => Format::datetime2timestamp($found->create_time),
                    'transaction' => $found->id,
                    'state'       => $found->state,
                    'receivers'   => $found->receivers,
                ]);
            }
        } else { // transaction not found, create new one

            // validate new transaction time
            if (Format::timestamp2milliseconds(1 * $request->params['time']) - Format::timestamp(true) >= Transaction::TIMEOUT) {
                $this->payme_requet->error(
                    PaycomException::ERROR_INVALID_ACCOUNT,
                    PaycomException::message(
                        'С даты создания транзакции прошло ' . Transaction::TIMEOUT . 'мс',
                        'Tranzaksiya yaratilgan sanadan ' . Transaction::TIMEOUT . 'ms o`tgan',
                        'Since create time of the transaction passed ' . Transaction::TIMEOUT . 'ms'
                    ),
                    'time'
                );
            }

            // create new transaction
            // keep create_time as timestamp, it is necessary in response
            $create_time                        = Format::timestamp(true);
            $transaction->paycom_transaction_id = $request->params->id;
            $transaction->paycom_time           = $request->params['time'];
            $transaction->paycom_time_datetime  = Format::timestamp2datetime($request->params['time']);
            $transaction->create_time           = Format::timestamp2datetime($create_time);
            $transaction->state                 = Transaction::STATE_CREATED;
            $transaction->amount                = $request->amount;
            $transaction->order_id              = $request->account('order_id');
            $transaction->save(); // after save $transaction->id will be populated with the newly created transaction's id.

            // send response
            return response()->json([
                'create_time' => $create_time,
                'transaction' => $transaction->id,
                'state'       => $transaction->state,
                'receivers'   => null,
            ]);
        }
    }


    public  function  PerformTransaction(Request $request){
        /**
         * {
            "method" : "PerformTransaction",
            "params" : {
               "id" : "5305e3bab097f420a62ced0b"
            }
        }
         */

        $transaction = new Transaction();
        // search transaction by id
        $found = $transaction->find_by($request->params);

        // if transaction not found, send error
        if (!$found) {
            $this->payme_requet->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }

        switch ($found->state) {
            case Transaction::STATE_CREATED: // handle active transaction
                if ($found->isExpired()) { // if transaction is expired, then cancel it and send error
                    $found->cancel(Transaction::REASON_CANCELLED_BY_TIMEOUT);
                    $this->payme_requet->error(
                        PaycomException::ERROR_COULD_NOT_PERFORM,
                        'Transaction is expired.'
                    );
                } else { // perform active transaction

                    $params = ['order_id' => $found->order_id];
                    $order  = new Order($request->id);
                    $order->find($params);
                    $order->changeState(Order::STATE_PAY_ACCEPTED);

                    // todo: Mark transaction as completed
                    $perform_time        = Format::timestamp(true);
                    $found->state        = Transaction::STATE_COMPLETED;
                    $found->perform_time = Format::timestamp2datetime($perform_time);
                    $found->save();

                    return response()->json([
                        'transaction'  => $found->id,
                        'perform_time' => $perform_time,
                        'state'        => $found->state,
                    ]);
                }
                break;

            case Transaction::STATE_COMPLETED: // handle complete transaction
                // todo: If transaction completed, just return it
               return response()->json([
                    'transaction'  => $found->id,
                    'perform_time' => Format::datetime2timestamp($found->perform_time),
                    'state'        => $found->state,
                ]);
                break;
            default:
                // unknown situation
                $this->payme_requet->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Could not perform this operation.'
                );
                break;
        }
    }

    public  function  CancelTransaction(Request $request){
            /**
             * {
            "method" : "CancelTransaction",
            "params" : {
            "id" : "5305e3bab097f420a62ced0b",
            "reason" : 1
            }
            }
             */

        $transaction = new Transaction();

        // search transaction by id
        $found = $transaction->find_by($request->params);

        // if transaction not found, send error
        if (!$found) {
            $this->payme_requet->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }

        switch ($found->state) {
            // if already cancelled, just send it
            case Transaction::STATE_CANCELLED:
            case Transaction::STATE_CANCELLED_AFTER_COMPLETE:
               return response()->json([
                    'transaction' => $found->id,
                    'cancel_time' => Format::datetime2timestamp($found->cancel_time),
                    'state'       => $found->state,
                ]);
                break;
            // cancel active transaction
            case Transaction::STATE_CREATED:
                // cancel transaction with given reason
                $found->cancel(1 * $request->params->reason);
                // after $found->cancel(), cancel_time and state properties populated with data

                // change order state to cancelled
                $order = new Order($request->id);
                $order->find($request->params);
                $order->changeState(Order::STATE_CANCELLED);

                // send response
               return response()->json([
                    'transaction' => $found->id,
                    'cancel_time' => Format::datetime2timestamp($found->cancel_time),
                    'state'       => $found->state,
                ]);
                break;

            case Transaction::STATE_COMPLETED:
                // find order and check, whether cancelling is possible this order
                $order = new Order($request->id);
                $order->find($request->params);
                if ($order->allowCancel()) {
                    // cancel and change state to cancelled
                    $found->cancel(1 * $request->params['reason']);
                    // after $found->cancel(), cancel_time and state properties populated with data

                    $order->changeState(Order::STATE_CANCELLED);
                    return response()->json([
                        'transaction' => $found->id,
                        'cancel_time' => Format::datetime2timestamp($found->cancel_time),
                        'state'       => $found->state,
                    ]);
                } else {
                    $this->payme_requet->error(
                        PaycomException::ERROR_COULD_NOT_CANCEL,
                        'Could not cancel transaction. Order is delivered/Service is completed.'
                    );
                }
                break;
        }

    }

    public  function  CheckTransaction(Request $request){
        /**
         * {
        "method" : "CheckTransaction",
                "params" : {
                          "id" : "5305e3bab097f420a62ced0b"
                }
        }
         */
        $transaction = new Transaction();
        $found  = $transaction->find_by($request->params);
        if (!$found) {
            $this->payme_requet->error(
                PaycomException::ERROR_TRANSACTION_NOT_FOUND,
                'Transaction not found.'
            );
        }
        response()->json([
            'create_time'  => Format::datetime2timestamp($found->create_time),
            'perform_time' => Format::datetime2timestamp($found->perform_time),
            'cancel_time'  => Format::datetime2timestamp($found->cancel_time),
            'transaction'  => $found->id,
            'state'        => $found->state,
            'reason'       => isset($found->reason) ? 1 * $found->reason : null,
        ]);
    }

    public  function  GetStatement(Request $request){
        /**\
         * {
        "method" : "GetStatement",
        "params" : {
                "from" : 1399114284039,
                 "to" : 1399120284000
        }
        }
         */
        // validate 'from'
        if (!isset($request->params->from)) {
            $this->payme_requet->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'from');
        }

        // validate 'to'
        if (!isset($request->params->to)) {
            $this->payme_requet->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'to');
        }

        // validate period
        if (1 * $request->params->from >= 1 * $request->params->to) {
            $this->payme_requet->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period. (from >= to)', 'from');
        }

        // get list of transactions for specified period
        $transaction  = new Transaction();
        $transactions = $transaction->report($request->params->from, $request->params->to);
        // send results back
        response()->json(['transactions' => $transactions]);
    }

    public  function  SetFiscalData(Request $request){

    }


    public function Authorize($request)
    {
        $headers = getallheaders();
        if (!$headers || !isset($headers['Authorization']) ||
            !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) ||
            base64_decode($matches[1]) != env('PAYMEN_LOGIN') . ":" . env('PAYMEN_PASSWORD')
        ) {
            throw new PaycomException(
                time(),
                'Insufficient privilege to perform this method.',
                PaycomException::ERROR_INSUFFICIENT_PRIVILEGE
            );
        }
        return true;
    }
}
