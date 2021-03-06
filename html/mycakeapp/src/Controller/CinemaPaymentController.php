<?php

namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\Event;
use Cake\Chronos\Chronos;
use Cake\I18n\Time;
use Cake\Validation\Validator;

class CinemaPaymentController extends CinemaBaseController
{
    public function initialize()
    {
        parent::initialize();
    }


    // 決済方法、ポイント選択
    public function index()
    {
        $session = $this->request->getSession();

        if (
            $creditcard = $this->BaseFunction->aliveCreditcard($this->Auth->user('id'))
        ) {
            // セッターによりもとのプロパティにいれると暗号化＆日付がymdになってしまう。
            $cardNumber = $creditcard->decryptCreditcard_number($creditcard->creditcard_number);
            $cardNumLast4 = substr($cardNumber, -4);
            $cardBrand = $creditcard->takeCardBland($cardNumber);
            $cardDate = $creditcard->changeCardDate($creditcard->expiration_date);
            $cardOwner = $creditcard->owner_name;

            // ポイント情報取得
            $point = $this->BaseFunction->pointInfo($this->Auth->user('id'));
            $point['have'] = $point['havePoint'];

            if ($this->request->is('post')) {
                $usePoint = $this->request->getData();
                $errors = $this->validatePoint($usePoint, $point['have']);

                if (empty($errors)) {
                    if (($usePoint['useTypes'] === '1') && ($usePoint['usePoint'] >= 1)) {
                        $point['use'] = $usePoint['usePoint'];
                    } elseif ($usePoint['useTypes'] === '2') {
                        $point['use'] = $point['have'];
                    } else {
                        $point['use'] = 0;
                    }

                    $session->write(['point' => $point]);
                    return $this->redirect(['action' => 'details']);
                } else {
                    $this->set(compact('errors'));
                }
            }

            $this->set(compact('cardNumLast4', 'cardBrand', 'cardDate', 'cardOwner', 'point'));
        } else {

            // クレジットカード未登録の場合クレジット登録画面へ遷移
            $session->write(['creditcard' => 'registration']);
            return $this->redirect(['controller' => 'CinemaCreditcard', 'action' => 'add']);
        }
    }


    public function details()
    {
        $session = $this->request->getSession();

        if (!($session->check('point'))) {
            $this->BaseFunction->deleteSessionReservation($session);
            return $this->redirect(['controller' => 'CinemaSchedules']);
        }

        $point = $session->read('point');

        $basicRate = $this->BasicRates->get($session->read('profile')['type']);
        $basicRatePrice = $basicRate->basic_rate;

        $totalPayment = $session->read('price');

        $discount = [];
        $specialDiscount = [];

        if ($session->read('discountTypeId')) {
            $discountType = $this->DiscountTypes->get($session->read('discountTypeId'));
            //特殊な割引は基本料金を上書きしてdiscount_detailsを表示する
            if (0 <= $discountType->discount_price) {
                $specialDiscount['type'] = $discountType->discount_type;
                $specialDiscount['discount_details'] = $discountType->discount_details;
                $basicRatePrice = $discountType->discount_price;
            } else {
                $discount['type'] = $discountType->discount_type;
                $discount['price'] = abs($discountType->discount_price);
            }
        }

        // ポイントタイプで全部使うを選択して、保有ポイントが支払金額よりも多い場合の処理
        if ($totalPayment < $point['use']) {
            $point['use'] = $totalPayment;
            $session->write(['point' => $point]);
        }

        $totalPayment -= $point['use'];
        $session->write(['totalPayment' => $totalPayment]);

        $this->set(compact('basicRatePrice', 'point', 'discount', 'specialDiscount', 'totalPayment'));
    }


    public function save()
    {
        $session = $this->request->getSession();

        if (!($session->check('totalPayment'))) {

            $this->BaseFunction->deleteSessionReservation($session);
            return $this->redirect(['controller' => 'CinemaSchedules']);
        }

        $payment = $this->Payments->newEntity();
        $reservation = $this->Reservations->newEntity();
        $discountLog = $this->DiscountLogs->newEntity();

        // 決済情報を取得し保存
        $creditcard = $this->Creditcards->find('all', [
            'conditions' => ['AND' => [['user_id' => $this->Auth->user('id')], ['is_deleted' => 0], ['expiration_date >=' => Time::now()->year . '-' . Time::now()->month . '-1']]]
        ])->first();
        $payment->creditcard_id = $creditcard->id;

        // 適用開始日を迎えた税率の中で一番新しいものを取得
        $tax = $this->Taxes->find('all', [
            'conditions' => ['start_date <=' => Time::now()],
            'order' => ['start_date' => 'DESC']
        ])->first();
        $payment->tax_id = $tax->id;
        $payment->total_payment = $session->read('totalPayment');

        if (($this->Payments->save($payment))) {

            // 予約情報を取得し保存
            $reservation->user_id = $this->Auth->user('id');
            $reservation->seat_id = $session->read('seat_id');
            $reservation->schedule_id = $session->read('schedule')['id'];
            $reservation->movie_id = $session->read('schedule')['movie_id'];
            $reservation->payment_id = $payment->id;
            $reservation->basic_rate_id = $session->read('profile')['type'];

            // getPoint
            $getPoint = $this->Points->newEntity();
            $getPoint->payment_id = $payment->id;
            $getPoint->user_id = $this->Auth->user('id');
            // 10%のポイント加算
            $getPoint->get_point = intval(floor($payment->total_payment * 0.1));

            // usePoint
            $point = $session->read('point');
            $payPoint = $point['use'];

            if (!($payPoint === 0)) {
                $point = $this->BaseFunction->pointInfo($this->Auth->user('id'));
                $pointArray = $point['info'];

                // 古いポイントから使うのでASCにソート
                foreach ($pointArray as $value) {
                    $created[] = $value['created'];
                }
                array_multisort($created, SORT_ASC, $pointArray);

                // use_pointを設定
                foreach ($pointArray as $point) {
                    // 使い切ったポイントカラムはスキップ
                    if ($point['get_point'] === $point['use_point']) {
                        continue;
                    }
                    if (!($point['use_point'] === 0)) {
                        $payPoint += $point['use_point'];
                    }
                    $payPoint -= $point['get_point'];
                    $point['use_point'] = $point['get_point'];
                    if ($payPoint <= 0) {
                        $point['use_point'] = $point['get_point'] + $payPoint;
                        $this->Points->save($point);
                        break;
                    }
                    $this->Points->save($point);
                }
            }

            if ($this->Reservations->save($reservation)) {

                // 獲得ポイントが0のときは保存しない
                if (!($getPoint->get_point === 0)) {
                    $this->Points->save($getPoint);
                }

                // 割引情報を取得し保存
                if ($session->check('discountTypeId')) {
                    $discountLog->reservation_id = $reservation->id;
                    $discountLog->discount_type_id = $session->read('discountTypeId');

                    if ($this->DiscountLogs->save($discountLog)) {
                    } else {
                        return $this->redirect(['action' => 'details']);
                    }
                }
                $this->Flash->success(__('The payment has been saved.'));
                return $this->redirect(['action' => 'completed']);
            }
        }
        $this->redirect(['action' => 'details']);
    }


    public function cancelIndex()
    {
        $session = $this->request->getSession();
        $session->delete('point');
        $this->redirect(['controller' => 'CinemaReservationConfirming', 'action' => 'confirm']);
    }
    public function cancelDetails()
    {
        $session = $this->request->getSession();
        $session->delete('totalPayment');
        $this->redirect(['action' => 'index']);
    }
    public function completed()
    {
        $session = $this->request->getSession();
        $session->write(['completed' => true]);
        $this->BaseFunction->deleteSessionReservation($session);
    }

    private function validatePoint($usePoint, $havePoint)
    {
        $validator = new Validator();

        $validator
            ->notEmptyString('usePoint', 'ポイントを入力してください')
            ->integer('usePoint', '数字を入力してください')
            ->lessThanOrEqual('usePoint', $havePoint, '保有ポイント内の値を入力してください');

        $errors = $validator->errors($usePoint);
        return $errors;
    }
}
