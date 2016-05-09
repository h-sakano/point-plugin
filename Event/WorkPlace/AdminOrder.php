<?php
/*
* This file is part of EC-CUBE
*
* Copyright(c) 2000-2016 LOCKON CO.,LTD. All Rights Reserved.
* http://www.lockon.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/
namespace Plugin\Point\Event\WorkPlace;

use Eccube\Entity\Customer;
use Eccube\Entity\Order;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * フックポイント汎用処理具象クラス
 *  - 拡張元 : 受注登録( 編集 )
 *  - 拡張項目 : ポイント付与判定・登録・ポイント調整
 *  - 商品明細の変更によるポイントの調整
 * Class AdminOrder
 * @package Plugin\Point\Event\WorkPlace
 */
class  AdminOrder extends AbstractWorkPlace
{
    /**
     * @var \Plugin\Point\Entity\PointInfo $PointInfo
     */
    protected $PointInfo;

    /**
     * @var \Plugin\Point\Helper\PointCalculateHelper\PointCalculateHelper $calculator
     */
    protected $calculator;

    /**
     * @var \Plugin\Point\Helper\PointHistoryHelper\PointHistoryHelper $history
     */
    protected $history;

    /**
     * AdminOrder constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->PointInfo = $this->app['eccube.plugin.point.repository.pointinfo']->getLastInsertData();
        $this->calculator = $this->app['eccube.plugin.point.calculate.helper.factory'];
        $this->history = $this->app['eccube.plugin.point.history.service'];
    }

    /**
     * 受注登録・編集
     *
     * @param FormBuilder $builder
     * @param Request $request
     * @param EventArgs|null $event
     */
    public function createForm(FormBuilder $builder, Request $request, EventArgs $event = null)
    {
        $builder = $event->getArgument('builder');
        $Order = $event->getArgument('TargetOrder');
        $Customer = $Order->getCustomer();

        $currentPoint = 0;
        $usePoint = 0;
        $addPoint = 0;

        $builder = $this->buildForm($builder);

        // 非会員受注の場合は制御を行わない.
        if (!$Customer instanceof Customer) {
            return;
        }

        $currentPoint = $this->calculateCurrentPoint($Order, $Customer);
        $usePoint = $this->app['eccube.plugin.point.repository.point']->getLatestUsePoint($Order);
        $usePoint = -($usePoint);

        // 受注編集時
        if ($Order->getId()) {
            $addPoint = $this->app['eccube.plugin.point.repository.point']->getLatestAddPointByOrder($Order);

            // 確定ステータスの場合
            if ($this->app['eccube.plugin.point.repository.pointstatus']->isFixedStatus($Order)) {
                $builder->addEventListener(
                    FormEvents::POST_SUBMIT,
                    function (FormEvent $event) use ($currentPoint, $usePoint, $addPoint) {
                        $form = $event->getForm();
                        $recalcCurrentPoint = $currentPoint + $usePoint - $addPoint;
                        $inputUsePoint = $form['plg_use_point']->getData();
                        $inputAddPoint = $form['plg_add_point']->getData();
                        if ($inputUsePoint > $recalcCurrentPoint + $inputAddPoint) {
                            $error = new FormError('保有ポイント以内になるよう調整してください');
                            $form['plg_use_point']->addError($error);
                            $form['plg_add_point']->addError($error);
                        }
                    }
                );
                // 非確定ステータスの場合
            } else {
                $builder->addEventListener(
                    FormEvents::POST_SUBMIT,
                    function (FormEvent $event) use ($currentPoint) {
                        $form = $event->getForm();
                        $inputUsePoint = $form['plg_use_point']->getData();
                        if ($inputUsePoint > $currentPoint) {
                            $error = new FormError('保有ポイント以内で入力してください');
                            $form['plg_use_point']->addError($error);
                        }
                    }
                );
            }
            // 新規受注登録
        } else {
            $builder->addEventListener(
                FormEvents::POST_SUBMIT,
                function (FormEvent $event) use ($currentPoint) {
                    $form = $event->getForm();
                    $inputUsePoint = $form['plg_use_point']->getData();
                    if ($inputUsePoint > $currentPoint) {
                        $error = new FormError('保有ポイント以内で入力してください');
                        $form['plg_use_point']->addError($error);
                    }
                }
            );
        }

        $builder->get('plg_use_point')->setData($usePoint);
        $builder->get('plg_add_point')->setData($addPoint);
    }

    /**
     * 受注登録・編集画面のフォームを生成する.
     *
     * @param $builder
     * @return mixed
     */
    protected function buildForm($builder)
    {
        $builder->add(
            'plg_use_point',
            'integer',
            array(
                'label' => '利用ポイント',
                'required' => false,
                'mapped' => false,
                'attr' => array(
                    'class' => 'form-control',
                ),
                'constraints' => array(
                    new Assert\GreaterThanOrEqual(array('value' => 0)),
                ),
            )
        )->add(
            'plg_add_point',
            'integer',
            array(
                'label' => '加算ポイント',
                'required' => false,
                'mapped' => false,
                'attr' => array(
                    'class' => 'form-control',
                ),
                'constraints' => array(
                    new Assert\GreaterThanOrEqual(array('value' => 0)),
                ),
            )
        );

        return $builder;
    }

    public function createTwig(TemplateEvent $event)
    {
        $parameters = $event->getParameters();

        $Order = $parameters['Order'];
        $Customer = $Order->getCustomer();

        // 会員情報が設定されていない場合はポイント関連の情報は表示しない.
        if (!$Customer instanceof Customer) {
            return;
        }

        $parameters = $event->getParameters();
        $source = $event->getSource();

        // フォーム項目の追加.
        $search = '<dl id="product_info_result_box__body_summary"';
        $view = 'Point/Resource/template/admin/Event/AdminOrder/order_point.twig';
        $snippet = $this->app['twig']->getLoader()->getSource($view);
        $replace = $snippet.$search;
        $source = str_replace($search, $replace, $source);

        // 保有ポイントの追加
        $search = '<div id="product_info_box"';
        $view = 'Point/Resource/template/admin/Event/AdminOrder/order_current_point.twig';
        $snippet = $this->app['twig']->getLoader()->getSource($view);
        $replace = $snippet.$search;
        $source = str_replace($search, $replace, $source);

        $orderIds = $this->app['eccube.plugin.point.repository.pointstatus']->selectOrderIdsWithFixedByCustomer(
            $Customer->getId()
        );
        $currentPoint = $this->app['eccube.plugin.point.repository.point']->calcCurrentPoint(
            $Customer->getId(),
            $orderIds
        );

        $parameters['currentPoint'] = $currentPoint;
        $event->setParameters($parameters);
        $event->setSource($source);
    }

    public function save(EventArgs $event)
    {
        $form = $event->getArgument('form');
        $Order = $event->getArgument('TargetOrder');
        $Customer = $Order->getCustomer();

        // 会員情報が設定されていない場合はポイント関連の処理は行わない
        if (!$Customer instanceof Customer) {
            return;
        }

        $usePoint = $form['plg_use_point']->getData();
        if (is_null($usePoint)) {
            $usePoint = 0;
        }
        $addPoint = $form['plg_add_point']->getData();
        if (is_null($addPoint)) {
            $addPoint = 0;
        }

        $beforeAddPoint = $this->app['eccube.plugin.point.repository.point']
            ->getLatestAddPointByOrder($Order);

        // 加算ポイントの更新
        if ($beforeAddPoint != $addPoint) {
            $this->updateAddPoint($Order, $Customer, $addPoint, $beforeAddPoint);
        }

        // 利用ポイントの更新
        $this->updateUsePoint($Order, $Customer, $usePoint);

        // ポイントの確定処理
        if ($Order->getOrderStatus()->getId() == $this->PointInfo->getPlgAddPointStatus()) {
            $this->fixPoint($Order, $Customer);
        }
    }

    /**
     * 受注削除
     * @param EventArgs $event
     */
    public function delete(EventArgs $event)
    {
        $Order = $event->getArgument('Order');
        $Customer = $Order->getCustomer();

        // 会員情報が設定されていない場合はポイント関連の処理は行わない
        if (!$Customer instanceof Customer) {
            return;
        }
        // ポイントステータスを削除にする
        $this->history->deletePointStatus($Order);

        // 会員ポイントの再計算
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $currentPoint = $this->calculateCurrentPoint($Order, $Customer);
        $this->app['eccube.plugin.point.repository.pointcustomer']->savePoint(
            $currentPoint,
            $Customer
        );

        // SnapShot保存
        $point = array();
        $point['current'] = $currentPoint;
        $point['use'] = 0;
        $point['add'] = 0;
        $this->saveAdjustUseOrderSnapShot($Order, $Customer, $point);
    }

    /**
     * 受注編集で購入商品の構成が変更した際に以下処理を行う
     *  - 前回付与ポイントの打ち消し
     *  - 今回付与ポイントの付与
     * @param $newAddPoint
     * @param $beforeAddPoint
     * @return bool
     */
    public function updateAddPoint(Order $Order, Customer $Customer, $newAddPoint, $beforeAddPoint)
    {
        // 以前の加算ポイントをマイナスで相殺
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveAddPointByOrderEdit($beforeAddPoint * -1);

        // 新しい加算ポイントの保存
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveAddPointByOrderEdit($newAddPoint);

        // 会員の保有ポイント保存
        $currentPoint = $this->calculateCurrentPoint($Order, $Customer);
        $this->app['eccube.plugin.point.repository.pointcustomer']->savePoint(
            $currentPoint,
            $Customer
        );

        // スナップショット保存
        $point = array();
        $point['current'] = $currentPoint;
        $point['use'] = 0;
        $point['add'] = $newAddPoint;
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveSnapShot($point);
    }

    /**
     * ポイント確定時処理
     *  -   受注ステータス判定でポイントの付与が確定した際の処理
     * @param $event
     * @return bool
     */
    protected function fixPoint(Order $Order, Customer $Customer)
    {
        // ポイントが確定ステータスなら何もしない
        if ($this->app['eccube.plugin.point.repository.pointstatus']->isFixedStatus($Order)) {
            return;
        }

        // ポイントを確定ステータスにする
        $this->fixPointStatus($Order, $Customer);

        // 会員の保有ポイント更新
        $currentPoint = $this->calculateCurrentPoint($Order, $Customer);
        $this->app['eccube.plugin.point.repository.pointcustomer']->savePoint(
            $currentPoint,
            $Customer
        );

        // SnapShot保存
        $fixedAddPoint = $this->app['eccube.plugin.point.repository.point']->getLatestAddPointByOrder(
            $Order
        );
        $point = array();
        $point['current'] = $currentPoint;
        $point['use'] = 0;
        $point['add'] = $fixedAddPoint;
        $this->saveFixOrderSnapShot($Order, $Customer, $point);
    }

    /**
     * 受注の利用ポイントを新しい利用ポイントに更新する
     *  - 相違あり : 利用ポイント打ち消し、更新
     *  - 相違なし : なにもしない
     * @param $event
     * @return bool
     */
    protected function updateUsePoint(Order $Order, Customer $Customer, $usePoint)
    {
        // 更新前の利用ポイントの取得
        $beforeUsePoint = -($this->app['eccube.plugin.point.repository.point']->getLatestUsePoint($Order));
        // 更新前の利用ポイントと新しい利用ポイントが同じであれば処理をキャンセル
        if ($usePoint == $beforeUsePoint) {
            return;
        }

        // 計算に必要なエンティティをセット
        $this->calculator->addEntity('Order', $Order);
        $this->calculator->addEntity('Customer', $Customer);
        // 計算使用値は絶対値
        $this->calculator->setUsePoint($usePoint);

        // 履歴保存
        // 更新前の利用ポイントを加算して相殺
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveUsePointByOrderEdit($beforeUsePoint);
        // 新しい利用ポイントをマイナス
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveUsePointByOrderEdit($usePoint * -1);

        // 会員ポイントの更新
        $currentPoint = $this->calculateCurrentPoint($Order, $Customer);
        $this->app['eccube.plugin.point.repository.pointcustomer']->savePoint(
            $currentPoint,
            $Customer
        );

        // SnapShot保存
        $point = array();
        $point['current'] = $currentPoint;
        $point['use'] = ($beforeUsePoint - $usePoint) * -1;
        $point['add'] = $this->calculator->getAddPointByOrder();
        $this->saveAdjustUseOrderSnapShot($Order, $Customer, $point);
    }

    /**
     * 付与ポイントを「確定」に変更する
     */
    protected function fixPointStatus(Order $Order, Customer $Customer)
    {
        // ポイントを確定状態にする
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->fixPointStatus();
    }

    /**
     * スナップショットテーブルへの保存
     *  - 利用ポイント調整時のスナップショット
     * @param $point
     * @return bool
     */
    protected function saveAdjustUseOrderSnapShot(Order $Order, Customer $Customer, $point)
    {
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveSnapShot($point);
    }

    /**
     * スナップショットテーブルへの保存
     *  - 付与ポイント確定時のスナップショット
     * @param $point
     * @return bool
     */
    protected function saveFixOrderSnapShot(Order $Order, Customer $Customer, $point)
    {
        $this->history->refreshEntity();
        $this->history->addEntity($Order);
        $this->history->addEntity($Customer);
        $this->history->saveSnapShot($point);
    }

    /**
     * 現在保有ポイントをログから再計算
     * @return int 保有ポイント
     */
    protected function calculateCurrentPoint(Order $Order, Customer $Customer)
    {
        $orderIds = $this->app['eccube.plugin.point.repository.pointstatus']->selectOrderIdsWithFixedByCustomer(
            $Customer->getId()
        );
        $currentPoint = $this->app['eccube.plugin.point.repository.point']->calcCurrentPoint(
            $Customer->getId(),
            $orderIds
        );

        if ($currentPoint < 0) {
            // TODO: ポイントがマイナス！
            // ポイントがマイナスの時はメール送信
            $this->app['eccube.plugin.point.mail.helper']->sendPointNotifyMail($Order, $currentPoint);
        }

        return $currentPoint;
    }
}
