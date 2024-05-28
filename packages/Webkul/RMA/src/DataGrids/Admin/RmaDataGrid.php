<?php

namespace Webkul\RMA\DataGrids\Admin;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\RMA\Models\RMAStatus;

class RmaDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function prepareQueryBuilder()
    {
        $customerId = null;

        $guestEmail = session('guestEmail');

        if (auth()->guard('customer')->check()) {
            session()->forget(['guestOrderId', 'guestEmail']);

            $customerId = auth()->guard('customer')->id();
        }

        $query = DB::table('orders');

        if ($guestEmail) {
            $query->where('orders.customer_email', $guestEmail)
                ->where('orders.is_guest', 1);
        } else {
            if ($customerId) {
                if (request()->route()->getName() == 'rma.customers.allrma') {
                    $query->where('orders.customer_id', $customerId);
                } elseif (auth()->guard('admin')->check()
                    && request()->route()->getName() == 'shop.guest.allrma') {
                    $query->where('orders.customer_email', $customerId);
                } else {
                    $query->where('orders.customer_email', $customerId);
                }
            }
        }

        $orderId = [];

        foreach (DB::table('orders')->get() as $order) {
            $orderId[] = $order->id;
        }

        $queryBuilder = DB::table('rma')
            ->leftJoin('orders', 'orders.id', '=', 'rma.order_id')
            ->addSelect(
                'rma.id',
                'rma.order_id',
                DB::raw('CONCAT(orders.customer_first_name, " ", orders.customer_last_name, ", ", orders.customer_id) as customer_name'),
                'rma.status',
                'rma.rma_status',
                'rma.order_status as order_status',
                'rma.created_at',
            )
            ->whereIn('order_id', $orderId);
          
        $this->addFilter('id', 'rma.id');
        $this->addFilter('order_id', 'rma.order_id');
        $this->addFilter('created_at', 'rma.created_at');
        $this->addFilter('customer_name', DB::raw('CONCAT(orders.customer_first_name, " ", orders.customer_last_name)'));

        return $queryBuilder;
    }

    /**
     * Add columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.id'),
            'type'       => 'number',
            'searchable' => false,
            'sortable'   => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'order_id',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.order-ref'),
            'type'       => 'number',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
            'closure'    => function ($row) {
                $routeName = request()->route()->getName();

                if ($routeName == 'admin.sales.rma.index'
                    && auth()->guard('admin')->check()) {

                    $route = route('admin.sales.orders.view', ['id' => $row->order_id]);
                } elseif ($routeName == 'rma.customers.allrma'
                    && auth()->guard('customer')->check()) {

                    $route = route('rma.customers.allrma', ['id' => $row->order_id]);
                } else {
                    return "#{$row->order_id}";
                }

                return '<a href="' . $route . '">' . '#' . $row->order_id . '</a>';
            },
        ]);

        $this->addColumn([
            'index'      => 'customer_name',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.customer-name'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
            'closure'    => function($row) {
                $customer =  explode(',', $row->customer_name);

                $customerId = '';

                $customerName = 'Guest';

               if (isset($row->customer_name)) {
                   $customerId = $customer[1];

                   $customerName = $customer[0];
                } 
                    
               if ($customerName == 'Guest') {
                    return $customerName;
                } else {
                    return '<a href="'.route('admin.customers.customers.view', $customerId).'" style="color:#000000; font-weight:bold; font-size:16px;">'.$customerName.'</a>';                
                }
            }
        ]);
        
        $this->addColumn([
            'index'      => 'rma_status',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.rma-status'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => false,
            'filterable' => false,
            'closure'    => function ($rma) {
                $rmaStatus = $rma->rma_status;

                if ($rmaStatus == null || $rmaStatus == RMAStatus::PENDING) {
                    if ($rma->status) {
                        return '<p class="label-active">' . trans('rma::app.status.status-name.solved') . '</p>';
                    } else {
                        return '<p class="label-pending">' . trans('rma::app.status.status-name.pending') . '</p>';
                    }
                } elseif ($rmaStatus == RMAStatus::RECEIVED_PACKAGE) {
                    if ($rma->status) {
                        return '<p class="label-active">' . trans('rma::app.status.status-name.solved') . '</p>';
                    } else {
                        return '<p class="label-closed">' . trans('rma::app.status.status-name.received-package') . '</p>';
                    }
                } elseif ($rmaStatus == RMAStatus::DECLINED) {
                    return '<p class="label-cancelled">' . trans('rma::app.status.status-name.declined') . '</p>';
                } elseif ($rmaStatus == RMAStatus::ITEM_CANCELED) {
                    return '<p class="label-cancelled">' . trans('rma::app.status.status-name.item-canceled') . '</p>';
                } elseif ($rmaStatus == RMAStatus::NOT_RECEIVED_PACKAGE_YET) {
                    return '<p class="label-pending">' . trans('rma::app.status.status-name.not-received-package-yet') . '</p>';
                } elseif ($rmaStatus == RMAStatus::DISPATCHED_PACKAGE) {
                    return '<p class="label-pending">' . trans('rma::app.status.status-name.dispatched-package') . '</p>';
                } elseif ($rmaStatus == RMAStatus::ACCEPT) {
                    if ($rma->status) {
                        return '<p class="label-active">' . trans('rma::app.status.status-name.solved') . '</p>';
                    } else {
                        return '<p class="label-active">' . trans('rma::app.status.status-name.accept') . '</p>';
                    }
                }
            },
        ]);

        $this->addColumn([
            'index'      => 'order_status',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.order-status'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
        ]);
        
        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('rma::app.admin.sales.rma.all-rma.index.datagrid.create'),
            'type'       => 'date_range',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        $routeName = request()->route()->getName();

        $iconClass = 'icon-eye';

        if ($routeName == 'admin.sales.rma.index'
            && auth()->guard('admin')->user()) {
            $route = 'admin.sales.rma.view';

            $iconClass = 'icon-view';

        } elseif ($routeName == 'rma.customers.allrma'
            && auth()->guard('customer')->user()) {

            $route = 'rma.customer.view';
        } else {
            $route = 'rma.customer.guestview';
        }

        $this->addAction([
            'title'  => trans('rma::app.shop.customer-rma-index.view'),
            'icon'   => $iconClass,
            'method' => 'GET',
            'url'    => function ($row) use ($route) {
                return route($route, $row->id);
            },
        ]);
    }
}
