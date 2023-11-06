<?php

namespace Webkul\RMA\Http\Controllers\Customer;

use Barryvdh\Debugbar\Twig\Extension\Dump;
use Codeception\Command\Console;
use Webkul\RMA\DataGrids\RMAList;
use Illuminate\Support\Facades\Mail;
use Webkul\RMA\Repositories\RMARepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\RMA\Repositories\RMAItemsRepository;
use Webkul\Sales\Repositories\OrderItemRepository;
use Webkul\RMA\Repositories\RMAImagesRepository;
use Webkul\RMA\Repositories\RMAReasonsRepository;
use Webkul\RMA\Repositories\RMAMessagesRepository;
use Webkul\RMA\Http\Controllers\Controller;
use Webkul\RMA\Mail\CustomerRmaCreationEmail;
use Webkul\RMA\Mail\CustomerConversationEmail;

use Webkul\Product\Facades\ProductImage;
class CustomerController extends Controller
{
    /**
     * Constructor
     * 
     * @param RMARepository $rmaRepository,
     * @param OrderRepository $orderRepository,
     * @param RMAItemsRepository $rmaItemsRepository,
     * @param OrderItemRepository $orderItemRepository,
     * @param RMAImagesRepository $rmaImagesRepository,
     * @param RMAReasonsRepository $rmaReasonRepository,
     * @param RMAMessagesRepository $rmaMessagesRepository
     */
    public function __construct(
        protected RMARepository $rmaRepository,
        protected OrderRepository $orderRepository,
        protected RMAItemsRepository $rmaItemsRepository,
        protected OrderItemRepository $orderItemRepository,
        protected RMAImagesRepository $rmaImagesRepository,
        protected RMAReasonsRepository $rmaReasonRepository,
        protected RMAMessagesRepository $rmaMessagesRepository
    ) {
        $this->isGuest = 1;

        if (auth()->guard('customer')->user()) {
            $this->isGuest = 0;
            $this->middleware('customer');
        }

        $this->_config = request('_config');
    }

    /**
     * Method to populate the Customer RMA index page which will be populated.
     *
     * @return Mixed
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(RMAList::class)->toJson();
        }
        
        if (auth()->guard('customer')->user()) {
            return view($this->_config['view']);
        } else {

            if (empty(session()->get('guestOrderId'))) {
                return redirect()->route('rma.guest.login');
            } else {
                return view($this->_config['view']);
            }
        }   
    }

    /**
     * Get details of RMA
     *
     * @param integer
     * return $value
     */
    public function view($id)
    {
        if (auth()->guard('customer')->user()) {
            $isGuest = 0;
            $rmaOrder = $this->rmaRepository->find($id)->order_id;

            $guestDetails = $this->orderRepository->findOneWhere([
                'is_guest'      => 0,
                'id'            => $rmaOrder,
                'customer_id'   => auth()->guard('customer')->user()->id,
            ]);

            if ($guestDetails) {
                $customerLastName = $guestDetails->customer_last_name;
                $customerFirstName = $guestDetails->customer_first_name;
            } else {
                return redirect()->route('rma.customers.allrma');
            }

            session()->forget('guestEmailId');
        } else {
            $isGuest = 1;

            if (! $this->rmaRepository->find($id)) {
                return redirect()->route('customer.session.index');
            }

            $guestDetails = $this->orderRepository->findOneWhere(['id' => session()->get('guestOrderId'),'customer_email' => session()->get('guestEmailId'),'is_guest'=>1]);

            $rmaOrder = $this->rmaRepository->find($id)->order_id;

            $guestDetails = $this->orderRepository->findWhere(['id' => $rmaOrder,'customer_email' => session()->get('guestEmailId'),'is_guest' => 1])->first();

            if (is_null($guestDetails)) {
                return redirect()->route('customer.session.index');
            }

            $customerLastName = $guestDetails->customer_last_name;
            $customerFirstName = $guestDetails->customer_first_name;

            if(! isset($guestDetails))  {
                $guestDetails = NULL;
            }
        }

        if (is_null($guestDetails)) {
            abort('404');
        }

        $rmaData = $this->rmaRepository->with('orderItem')->findOneWhere([ 'id' => $id ]);

        $rmaImages = $this->rmaImagesRepository->findWhere(['rma_id' => $id]);

        $customer = auth()->guard('customer')->user();

        $reasons = $this->rmaItemsRepository->with('getReasons')->findWhere(['rma_id' => $id]);

        $productDetails = $this->rmaItemsRepository->findWhere(['rma_id' => $id]);

        $orderItemsRMA = $this->rmaItemsRepository->findWhere(['rma_id' => $rmaData['id']]);

        $order = $this->orderRepository->findOrFail($rmaData['order_id']);

        $ordersItem = $order->items;

        foreach ($orderItemsRMA as $orderItemRMA) {
            $orderItem[] = $orderItemRMA->id;
        }

        $orderData = $ordersItem->whereIn('id', $orderItem);

        foreach ($order->items as $key => $configurableProducts) {
            if ($configurableProducts['type'] == 'configurable'){
                $skus[] = $configurableProducts['child'];
            } else {
                $skus[] = $configurableProducts['sku'];
            }
        }

        if (! is_null($id)) {
            $messages = $this->rmaMessagesRepository
                        ->where('rma_id', $id)
                        ->orderBy('id','desc')
                        ->paginate(5);
        }

        // get the price of each product
        foreach ($productDetails as $key => $orderItemsDetails) {
            $value[] = $orderItemsDetails->rmaOrderItem->price;
        }

        return view($this->_config['view'], compact(
            'skus',
            'rmaData',
            'reasons',
            'isGuest',
            'messages',
            'customer',
            'rmaImages',
            'productDetails',
            'customerLastName',
            'customerFirstName'
        ));
    }

    /**
     *
     * create the RMA for tha specific Order
     *
     */
    public function create()
    {
        if(! is_array($this->getOrdersForRMA(1, 5, ''))) {
            return redirect()->route('rma.customers.allrma');
        }

        extract($this->getOrdersForRMA(1, 5, ''));
        $reasons = $this->rmaReasonRepository->findWhere(['status'=> '1']);

        $orderItems = $orders;

        return view(
            $this->_config['view'],
            compact(
                'orderItems',
                'customerEmail',
                'customerName',
                'reasons'
            )
        );
    }

    /**
     * fetch the order from the specific order id
     */
    public function getProducts($orderId, $resolution)
    {
        
        if (gettype($resolution) == 'array') {
            //check the orderItems by selected $order_id
            $invoiceItems = app('Webkul\Sales\Repositories\InvoiceItemRepository');
            $shipmentItems = app('Webkul\Sales\Repositories\ShipmentItemRepository');
            $productImageRepository = app('Webkul\Product\Repositories\ProductImageRepository');
            $productRepository = app('Webkul\Product\Repositories\ProductRepository');
            $enableRMAForPendingOrder = core()->getConfigData('sales.rma.setting.enable_rma_for_pending_order');

            $order = $this->orderRepository->findOrFail($orderId);
            $orderItems = $order->items;

            //check the product's shipment and invoice is generated or not
            foreach ($orderItems as $orderItem) {
                $itemsId[] = $orderItem['id'];
                $productId[] = $orderItem['product_id'];
            }

            $invoiceCreatedItems = $invoiceItems->findWhereIn('order_item_id', $itemsId);
            $shippedOrderItems = $shipmentItems->findWhereIn('order_item_id', $itemsId);
            $productImageCounts = $productImageRepository->findWhereIn('product_id', $productId)->count();

            foreach ($productId as $orderItemIds) {
                $allProducts[] = $productRepository->find($orderItemIds);
            }
            $productImage = [];
            foreach ($allProducts as $product) {
             
                if ($product && $product->id) {
                    $productImage[$product->id] = $product->getTypeInstance()->getBaseImage($product);
                }
            }

            foreach ($invoiceCreatedItems as $invoiceCreatedItem) {
                $invoiceCreatedItemId[] = $invoiceCreatedItem->order_item_id;
            }

            foreach ($shippedOrderItems as $shippedOrderItem) {
                $shippedOrderItemId[] = $shippedOrderItem->order_item_id;
            }

            if ($enableRMAForPendingOrder == 1) {
                $resolutions = ['Cancel Items'];
            } else {
                $resolutions = [];
            }

            $orderStatus = ['Not Delivered'];

            if (isset($shippedOrderItemId)) {
                $resolutions = ['Cancel Items','Exchange'];
                
                if ( count($shippedOrderItemId) == count($itemsId)) {
                   $orderStatus = ['Not Delivered','Delivered'];
                }
            }

            if (isset($invoiceCreatedItemId)) {
                $resolutions = ['Return','Exchange'];
            }

            if (isset($invoiceCreatedItemId) && isset($shippedOrderItemId)) {
                $resolutions = ['Return','Exchange'];
            }

            if (isset($order) && $order->status == 'completed') {
                $orderStatus = ['Delivered'];
            }

            $order = $this->orderRepository->findOrFail($orderId);

            $orderItems = $this->orderItemRepository->where("order_id", $order->id)->latest()->paginate(5);

            return [
                'search_results' => $orderItems,
                'resolutions'    => $resolutions,
            ]; 

            return response()->json([
                'orderId'               => $orderId,
                'orderItems'            => $orderItems,
                'orderStatus'           => $orderStatus,
                'resolutions'           => $resolutions,
                'productImageCounts'    => $productImageCounts,
                'productImage'          => $productImage
            ]);
            
        } else {
            $response = $this->fetchOrderDetails($orderId, $resolution);

            return $response;
        }
    }

    /**
     * fetch order details
     */
    private function fetchOrderDetails($orderId, $resolution)
    {
        $invoiceItems = app('Webkul\Sales\Repositories\InvoiceItemRepository');
        $shipmentItems = app('Webkul\Sales\Repositories\ShipmentItemRepository');
        $productRepository = app('Webkul\Product\Repositories\ProductRepository');
        $productImageRepository = app('Webkul\Product\Repositories\ProductImageRepository');

        $order = $this->orderRepository->findOrFail($orderId);

        $allOrderItems = $order->items;
        $rmaDataByOrderId = $this->rmaRepository->findWhere([
            'order_id' => $orderId,
        ]);

        $rmaId = [];
        if (count($rmaDataByOrderId) > 0) {
            foreach ($rmaDataByOrderId as $rmaDataId) {
                $rmaId[] = $rmaDataId->id;
            }
        }

        $rmaOrderItems = $this->rmaItemsRepository->findWhereIn('rma_id', $rmaId);

        $qty = [];
        $orderItems = [];
        $allProducts = [];
        $rmaOrderItemId = [];
        $countRmaOrderItems = [];

        foreach ($rmaOrderItems as $key => $rmaOrderItems) {
            $rmaOrderItemId[$rmaOrderItems['order_item_id']] = $rmaOrderItems['order_item_id'];
            $countRmaOrderItems[] = $rmaOrderItems['order_item_id'];
        }

        foreach ($allOrderItems as $key => $item) {
            $itemsId[] = $item->id;
        }

        foreach ($itemsId as $key => $orderItemId) {
            $orderItemsData = $this->orderItemRepository->findWhere([
                'id'        => $orderItemId,
                'order_id'  => $orderId,
            ]);

            if ($resolution != 'Cancel Items') {
                // remove order items
                foreach ($orderItemsData as $index => $orderItemData) {
                    $isOrderItemValid = in_array($orderItemData->type, ['downloadable', 'virtual', 'booking']);

                    if ($isOrderItemValid) {
                        unset($orderItemsData[$index]);
                    }
                }    
            }

            if (sizeof($orderItemsData) > 0) {
                $orderItems[] = $orderItemsData;
            }
        }

        foreach ($orderItems as &$val) {
            $val = $val[0];
            unset($val[0]);
        }

        $filteredData = $orderItems;
        $checkRmaCreatedOrNot = $this->rmaItemsRepository->findWhereIn('order_item_id', $itemsId)->count();

        if ($checkRmaCreatedOrNot > 0) {
            $rmaItems = $this->rmaItemsRepository->findWhereIn('order_item_id', $itemsId);

            foreach ($rmaItems as $rmaItem) {
                $rmaOrderItemQty[$rmaItem->order_item_id][$rmaItem->id] = $rmaItem->quantity;
            }

            foreach ($rmaOrderItemQty as $key => $itemQty) {
                $qtyAddedrma[$key] = array_sum($itemQty);
                $rmaItemsId[] = $key;
            }

            foreach ($orderItems as $key => $orderItem) {
                if (in_array ($orderItem->id,$rmaItemsId)) {
                    $qty[$orderItem->id] = $orderItem->qty_ordered - $qtyAddedrma[$orderItem->id];
                } else {
                    $qty[$orderItem->id] = $orderItem->qty_ordered;
                }
            }

            foreach ($orderItems as $orderItem) {
                if ($qty[$orderItem->id] != 0) {
                    $isExisting = false;
                    foreach ($filteredData as $data) {
                        if ($data->id == $orderItem->id) {
                            $isExisting = true;
                        }
                    }

                    if (! $isExisting) {
                        $filteredData[] = $orderItem;
                    }
                }
            }

        } else {
            foreach ($orderItems as $orderItem) {
                $qty[$orderItem->id] = $orderItem->qty_ordered;
            }
        }

        $productId = [];

        $orderItemsData = array_unique($filteredData);
        foreach ($orderItemsData as $orderItem) {
            $productId[] = $orderItem->product_id;

            $allProducts[] = $productRepository->find($orderItem->product_id);
        }

        $productImage = [];
        foreach ($allProducts as $product) {
            if ($product && $product->type == 'configurable' && $product->id ) {
                foreach ($allOrderItems as $orderItems){
                    $productImage[$product->id] = $orderItems->product->getTypeInstance()->getBaseImage($orderItems) ;
                }
            } elseif ($product && $product->id) {
                $productImage[$product->id] = ProductImage::getProductBaseImage($product);
            }
        }

        $orderItemsByItemId = $this->orderItemRepository->findWhereIn('id',$itemsId);

        $html = [];

        foreach($orderItemsByItemId as $key => $configurableProducts) {
            if ($configurableProducts->type == 'configurable') {
                $additional = '';
                // $additional = $configurableProducts->getOptionDetailHtml();
                $html[$configurableProducts->id] = str_replace(',', '<br>',  $additional);

                $attributeValue = app('Webkul\RMA\Helpers\Helper')->getOptionDetailHtml($configurableProducts->additional['attributes'] ?? []);

                $child[$configurableProducts->id] = [
                    'attribute' => $attributeValue,
                    'sku'       => $configurableProducts->child->sku,
                    'name'      => $configurableProducts->child->name,
                ];

                $variants = $configurableProducts->product->variants;

            } else {
                $child[$configurableProducts->id] = $configurableProducts->sku;
            }
        }

        $productImageCounts = $productImageRepository->findWhereIn('product_id',$productId)->count();

        $invoiceCreatedItems = $invoiceItems->findWhereIn('order_item_id',$itemsId);
        $shippedOrderItems = $shipmentItems->findWhereIn('order_item_id',$itemsId);

        $invoiceCreatedItemId = [];
        foreach ($invoiceCreatedItems as $invoiceCreatedItem) {
            $invoiceCreatedItemId[] = $invoiceCreatedItem->order_item_id;
        }

        $shippedOrderItemId = [];
        $shippedProductId = [];
        foreach ($shippedOrderItems as $shippedOrderItem) {
            $shippedOrderItemId[] = $shippedOrderItem->order_item_id;
            $shippedProductId[] = $shippedOrderItem->product_id;
        }

        $resolutionResponse = ['Cancel Items'];
        $orderStatus = ['Not Delivered'];

        if (! empty($shippedOrderItemId)) {
            $resolutionResponse = ['Cancel Items','Exchange'];
            $orderStatus = ['Not Delivered','Delivered'];
        }        

        if (! empty($invoiceCreatedItemId)) {
            if ( count(array_unique($invoiceCreatedItemId)) == count($itemsId)) {
                $resolutionResponse = ['Return','Exchange'];
                $orderStatus = ['Not Delivered'];
            }
            if ( count(array_unique($invoiceCreatedItemId)) != count($itemsId)) {
                $resolutionResponse = ['Return','Exchange','Cancel Items'];
                $orderStatus = ['Not Delivered'];
            }
        }   

        if (! empty($invoiceCreatedItemId) && ! empty($shippedOrderItemId)) {
            if ( count(array_unique($invoiceCreatedItemId)) == count($itemsId)) {
                $resolutionResponse = ['Return','Exchange']; 
                $orderStatus = ['Not Delivered'];               
            }

            if ($invoiceCreatedItemId && $shippedOrderItemId) {
                $resolutionResponse = ['Return','Exchange'];
                $orderStatus = ['Not Delivered','Delivered'];
            }           
        }      

        $items = [];

        foreach ($orderItemsData  as $orderItemData) {
            if ($qty[$orderItemData->id] != 0) {
                $items[] =  $orderItemData;
            }
        }

        $orderData = [];
        if (! empty($invoiceCreatedItemId) || ! empty($shippedOrderItemId)) {
            if (
                (isset($resolution) && $resolution == 'Exchange')
                || ('Return' && $resolution != 'Cancel Items')
            ) {
                foreach ($items as $item) {
                    $isExisting = false;
                    foreach ($orderData as $existingData) {
                        if ($item->id == $existingData->id) {
                            $isExisting = true;
                        }
                    }

                    if (! $isExisting && in_array($item->id, $invoiceCreatedItemId)) {
                        if ($item->product->type == 'configurable') {
                            $orderData[] = $item;
                        } else if (! in_array($item->id,$shippedOrderItemId)) {
                            $orderData[] = $item;
                        } else {
                            $orderData[] = $item;
                            if ($invoiceCreatedItemId && $shippedOrderItemId) {
                                $orderStatus = ['Not Delivered','Delivered'];
                            }else{
                                $orderStatus = ['Not Delivered']; 
                            }                                                                                
                        }
                    }
                }
            }
        }       

        if (isset($resolution) &&  ( $resolution == 'Cancel Items' || $resolution == 'Exchange')) {
            foreach ($items as $item) {
                if (! in_array($item->id, $invoiceCreatedItemId))  {
                    $isExisting = false;
                    foreach ($orderData as $existingData) {
                        if ($item->id == $existingData->id) {
                            $isExisting = true;
                        }
                    }
                    
                    if ($item->product->type == 'configurable') {
                        $orderData[] = $item;
                    } else if (! $isExisting) {
                        $orderData[] = $item;
                    }
                }
            }
        }

        if (is_null($resolution)) {
            $orderData = [];
        }

        if (isset($order) && $order->status == 'completed') {
            $orderStatus = ['Delivered'];
        }

        return response()->json([
            'quantity'           => $qty,
            'html'               => $html,
            'child'              => $child,
            'variants'           => $variants??[],
            'itemsId'            => $itemsId,
            'orderItems'         => $orderData,
            'orderStatus'        => $orderStatus,
            'productImage'       => $productImage,
            'rmaOrderItemId'     => $rmaOrderItemId,
            'resolutions'        => $resolutionResponse,
            'productImageCounts' => $productImageCounts,
            'countRmaOrderItems' => $countRmaOrderItems,
            'shippedProductId'   => $shippedProductId,
            'shippingOrderStatus'   => count($shippedOrderItemId) > 0 ? 1 : 0,
        ]);

    }

    /**
     * Store a newly created rma.
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $this->validate(request(), [
            'quantity' => 'required',
            'resolution' => 'required',
            'order_status' => 'required',
            'order_item_id' => 'required',
        ]);

        $items = [];

        $data = request()->all();
      
        if (! empty($data['information'])) {
            if (str_word_count($data['information'], 0) > 100) {
                $words = str_word_count($data['information'], 2);
                $pos   = array_keys($words);
                $info  = substr($data['information'], 0, $pos[100]) . '...';
            } else {
                $info = $data["information"];
            }
        }
        
        $data['order_items'] = [];

        foreach ($data['order_item_id'] as $orderItemId) {
            $orderItem = $this->orderItemRepository->find($orderItemId);

            array_push($data['order_items'], $orderItem);

            array_push($items, [
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
            ]);
        }
        $orderRMAData = [
            'status'        => '',
            'order_id'      => $data['order_id'],
            'resolution'    => $data['resolution'],
            'information'   => !empty($info) ? $info : '',
            'order_status'  => $data['order_status'],
            'rma_status'    => 'Pending',
        ];

        $rma = $this->rmaRepository->create($orderRMAData);
        $lastInsertId = $rma->id;

        if (isset($data['images'])) {
            $imageCheck = implode(",", $data['images']);
        }

        $data['rma_id'] = $lastInsertId;

        // insert images
        if (! empty($imageCheck)) {
            foreach ($data['images'] as $itemImg) {
                $this->rmaImagesRepository->create([
                    'rma_id' => $lastInsertId,
                    'path' => !empty($itemImg) ?? $itemImg->getClientOriginalName(),
                ]);
            }
        }
        // insert orderItems
        foreach ($items as $key => $itemId) {
            $orderItemRMA = [
                'rma_id' => $lastInsertId,
                'order_item_id' => $itemId['order_item_id'],
                'quantity' => $data['quantity'][$itemId['order_item_id']],
                'rma_reason_id' => $data['rma_reason_id'][$itemId['order_item_id']],
                'variant_id'    => isset($data['variant'][$key]) ? $data['variant'][$key] : null,
            ];

            $rmaOrderItem = $this->rmaItemsRepository->create($orderItemRMA);
        }
        $data['reasonsData'] =  $this->rmaReasonRepository->findWhereIn('id', $data['rma_reason_id']);

        foreach ($data['reasonsData'] as $reasons) {
            $data['reasons'][] = $reasons->title;
        }

        // save the images in the public path
        $this->rmaImagesRepository->uploadImages($data, $rma);

        $orderItemsRMA = $this->rmaItemsRepository->findWhere(['rma_id' => $lastInsertId]);

        $orderId = $this->rmaRepository->find($lastInsertId)->order_id;

        $order = $this->orderRepository->findOrFail($orderId);

        $ordersItem = $order->items;

        $orderItem = [];
        foreach ($orderItemsRMA as $orderItemRMA) {
            $orderItem[] = $orderItemRMA->order_item_id;
        }

        $orderData = $ordersItem->whereIn('id', $orderItem);

        foreach ($orderData as $key => $configurableProducts) {
            if ($configurableProducts['type'] == 'configurable'){
                $data['skus'][] = $configurableProducts['child'];
            }
        }
      
        if ($rmaOrderItem) {
            try {
                Mail::queue(new CustomerRmaCreationEmail($data));
                session()->flash('success', trans('shop::app.customer.signup-form.success-verify'));
            } catch (\Exception $e) {
                session()->flash('success', trans('shop::app.customer.signup-form.success-verify-email-unsent'));
            }

            session()->flash('success', trans('admin::app.response.create-success', ['name' => 'Request']));
           
            if (auth()->guard('customer')->user()) {
                return redirect()->route('rma.customers.allrma');
            } else {
                return redirect()->route('rma.customers.guestallrma');
            }

            return redirect()->route('rma.customers.allrma');
        } else {
            session()->flash('error', trans('shop::app.customer.signup-form.failed'));

            return redirect()->route('rma.customer.view');
        }
    }

    /**
     * Save rma status by customer
     *
     * @return Illuminate\Http\Response
     */
    public function saveStatus()
    {
        $data = request()->all();
    
        if (isset($data['close_rma'])) {
            $status = 1;
            $rma = $this->rmaRepository->find($data['rma_id']);

            if ($rma) {
                $orderId = $rma->order_id;
                $order = $this->orderRepository->find($orderId);
                $order->update(['status' => 'closed']);
    
                $this->rmaRepository->find($data['rma_id'])->update(['status' => $status, 'rma_status' => 'Solved']);
            }
        }

        session()->flash('success', trans('rma::app.response.update-status', ['name' => 'Status']));

        return back();
    }

    /**
     * Send message by Email
     *
     * @return Illuminate\Http\Response
     */
    public function sendMessage()
    {
        $data = request()->all();

        $conversationDetails['adminName'] = 'Admin';
        $conversationDetails['message'] = $data['message'];
        $conversationDetails['adminEmail'] = core()->getConfigData('emails.configure.email_settings.admin_email');

        if (auth()->guard('customer')->user())
            $this->isGuest = 0;

        if (isset($this->isGuest) && $this->isGuest == 1) {
            $conversationDetails['customerEmail'] = $this->orderRepository->find(session()->get('guestOrderId'))->customer_email;
        } else {
            $conversationDetails['customerEmail'] = auth()->guard('customer')->user()->email;
        }
       
        $storeMessage =  $this->rmaMessagesRepository->create($data); 
      

        if ($storeMessage) {

            try {
                Mail::queue(new CustomerConversationEmail($conversationDetails));

                session()->flash('success', trans('shop::app.customer.signup-form.success-verify'));

            } catch (\Exception $e) {

                session()->flash('info', trans('rma::app.response.send-message', ['name' => 'Message']));
            }

                session()->flash('success', trans('rma::app.response.send-message', ['name' => 'Message']));

                return redirect()->back();

        } else {
            session()->flash('error', trans('shop::app.customer.signup-form.failed'));

            return redirect()->back();
        }
        session()->flash('success', trans('rma::app.response.send-message', ['name' => 'Message']));

        return redirect()->back();
    }

    /**
     * Reopen RMA
     *
     * @param int $id
     * 
     * @return Illuminate\Http\Response
     */
    public function reopenRMA($id)
    {
        $rma = $this->rmaRepository->find($id);       
    
        if ($rma) {
            $rma->rma_status = null;
            $rma->status = 0;
            $rma->save();
        }
            
        return redirect()->route($this->_config['redirect']);
    }

    /**
     * login for the Guest user's
     * which is not logged in the system
     */
    public function guestLogin()
    {
        $isGuest = $this->isGuest;

        if ($isGuest == 1) {
            return view($this->_config['view']);
        } else if($isGuest == 0) {
           return redirect()->route('rma.customers.allrma');
        }
    }

    /**
     * get the requested data from the Guest
     */
    public function guestLoginCreate()
    {
        $guestUserData = request()->all();
        $checkData = $this->orderRepository->findWhere([
                'id' => $guestUserData['order_id'], 'customer_email' => $guestUserData['email'], 'is_guest' => 1
            ])->first();

        if (isset($checkData)) {
            session()->put('guestOrderId',$guestUserData['order_id']);
            session()->put('guestEmailId',$guestUserData['email']);

            return redirect()->route('rma.customers.guestallrma')->with('guestUserData');
        } else {
            return redirect()->back()->with('error','Invalid details for guest');
        }

    }

    /**
     * Create the RMA for tha specific Order
     *
     */
    public function guestRMACreate()
    {
        dd('asdfasdf');
        $guestOrderId = session()->get('guestOrderId');

        $guestEmailId = session()->get('guestEmailId');

        if (auth()->guard('customer')->user()) {
            session()->flash('error', trans('rma::app.response.permission-denied'));

            return redirect()->back();

        } else if (isset($guestEmailId) && isset($guestOrderId) &&  !empty($guestEmailId) && !empty($guestOrderId) || $this->isGuest == 1) {

            $allOrderItems = $this->orderRepository->orderBy('id', 'desc')->with('items')->findWhere(
                [
                    'customer_email' => $guestEmailId,
                    ['status', '<>', 'canceled'],
                    ['status', '<>', 'closed']
                ]
            );

            $orderData = $this->orderRepository->findOneWhere(
                ['id' => $guestOrderId ,
                ['status', '<>', 'canceled']]
            );

            if (! $orderData) {
                return redirect()->route('customer.session.index');
            }

            $customerEmail = $orderData->customer_email;

            $customerName = $orderData->customer_first_name." ".$orderData->customer_last_name;
        }

        $returnData = $this->createRMA($allOrderItems);

        $orderItems =  $returnData["orderItems"];
        $reasons =  $returnData["reasons"];

        return view(
            $this->_config['view'],
            compact(
                'customerName',
                'customerEmail',
                'orderItems',
                'reasons'
            )
        );
    }

    /**
     * Create Customer RMA
     *
     */
    public function customerRMACreate() 
    {
        $customer = auth()->guard('customer')->user();

        $customerEmail = $customer->email;

        $customerName = $customer->first_name ." ". $customer->last_name;

        $allOrderItems = $this->orderRepository
            ->orderBy('id','desc')
            ->with('items')
            ->findWhere([
                'customer_id' => $customer->id,
                ['status', '<>', 'canceled']
            ]);

        $returnData = $this->createRMA($allOrderItems);
        $orderItems =  $returnData["orderItems"];
        $reasons =  $returnData["reasons"];

        return view(
            $this->_config['view'],
            compact(
                'customerName',
                'customerEmail',
                'orderItems',
                'reasons'
            )
        );
    }

    public function addReason($id) {
        $reasons = $this->rmaReasonRepository->create([
            'status'=> '1',
             'title' => request()['inputData']]);
             
        return response()->json(['reasons' => $reasons]);
        // return view($this->_config['view'],compact('reasons')); 
    }

    /**
     * Create RMA
     *
     * @param array $allOrderItems
     * @return array
     */
    public function createRMA($allOrderItems) 
    {
        $orderItems = collect();

        $reasons = $this->rmaReasonRepository->findWhere(['status'=> '1']);

        $defaultAllowedDays = core()->getConfigData('sales.rma.setting.default_allow_days');
        
        $enableRMAForCanceledOrder = core()->getConfigData('sales.rma.setting.enable_rma_for_cancel_order');

        $enableRMAForPendingOrder = core()->getConfigData('sales.rma.setting.enable_rma_for_pending_order');

        foreach ($allOrderItems as $key => $orderItem) {            
            if (! $defaultAllowedDays || ($orderItem->created_at->addDays($defaultAllowedDays)->format('Y-m-d') >= now()->format('Y-m-d'))) {
                $orderItems->push($orderItem);
            }

            if (! $enableRMAForPendingOrder && $orderItem->status == 'pending') {
                unset($orderItems[$key]);
            }
        }

        $orderIds = $orderItems->pluck('id')->toArray();

        $rmaCollection = $this->rmaRepository
                        ->findWhereIn('order_id', $orderIds)
                        ->groupBy('order_id');

        foreach ($rmaCollection->toArray() as $rma) {
            $rmaIds = [];

            foreach ($rma as $rmaPart) {
                $rmaIds[] = $rmaPart['id'];
            }

            $totalRMAQty = 0;
            $rmaItems = $this->rmaItemsRepository
                        ->findWhereIn('rma_id', $rmaIds);

            foreach ($rmaItems as $rmaItem) {
                $totalRMAQty += $rmaItem->quantity;
            }

            foreach ($orderItems as $key => $order) {
                if ($rma[0]['order_id'] == $order->id) {
                    if ($totalRMAQty == $order->total_qty_ordered) {
                        unset($orderItems[$key]);
                    }
                }

                if ($enableRMAForCanceledOrder) {
                    if ($order->status == "canceled") {
                        unset($orderItems[$key]);
                    }
                }
            }
        }

        foreach ($orderItems as $key => $orderValue) {
            foreach ($orderValue->items as $itemValue) {
                if (in_array($itemValue->type, ["virtual", "downloadable", "booking"])
                    && (! core()->getConfigData('sales.rma.setting.enable_rma_for_digital_products') 
                    || $orderValue->status == "completed")
                ) {
                    unset($orderItems[$key]);
                }
            }
        }

        $returnData['orderItems'] = $orderItems;
        $returnData['reasons'] = $reasons;

        return $returnData;
    }

    /**
     * Search Order
     *
     * @param int $orderId
     * @return orders
     */
    public function searchOrder($orderId)
    {
        extract($this->getOrdersForRMA(1, 5, $orderId == 'all' ? '' : $orderId));

        return $orders;
    }

    /**
     * Get Orders for RMA
     *
     * @param array ...$params
     * @return array
     */
    private function getOrdersForRMA(...$params)
    {
        list($page, $perPage, $search) = $params;

        $guestOrderId = session()->get('guestOrderId');
        $guestEmailId = session()->get('guestEmailId');

        if (isset($guestEmailId) && isset($guestOrderId) && $this->isGuest == 1) {
            $allOrderItems = $this->orderRepository->orderBy('id', 'desc')->with('items')->findWhere([
                'customer_email' => $guestEmailId,
                ['status', '<>', 'canceled'],
                ['status', '<>', 'closed']
            ]);
            
            $orderData = $this->orderRepository->findOneWhere(
                ['id' => $guestOrderId ,
                ['status', '<>', 'canceled']]
            );

            if(! $orderData){
                return redirect()->route('customer.session.index');
            }

            $customerEmail = $orderData->customer_email;

            $customerName = $orderData->customer_first_name." ".$orderData->customer_last_name;
        } else {
            $customer = auth()->guard('customer')->user();

            $customerEmail = $customer->email;
            $customerName = $customer->first_name . " " . $customer->last_name;

            $allOrderItems = $this->orderRepository
                            ->orderBy('id','desc')
                            ->with('items')
                            ->findWhere([
                                'customer_id' => $customer->id,
                                ['status', '<>', 'canceled'],
                                ['status', '<>', 'closed']
                            ]);
        }

        if ($search != "") {
            $allOrderItems = $allOrderItems
                            ->where('increment_id', $search);
        }

        $defaultAllowedDays = core()->getConfigData('sales.rma.setting.default_allow_days');

        $enableRMAForCanceledOrder = core()->getConfigData('sales.rma.setting.enable_rma_for_cancel_order');

        $orders = collect();

        foreach ($allOrderItems as $orderItem) {
            if (! $defaultAllowedDays || ($orderItem->created_at->addDays($defaultAllowedDays)->format('Y-m-d') >= now()->format('Y-m-d'))) {
                $orders->push($orderItem);
            }
        }

        $orderIds = $orders->pluck('id')->toArray();

        $rmaCollection = $this->rmaRepository
                        ->findWhereIn('order_id', $orderIds)
                        ->groupBy('order_id');

        foreach ($rmaCollection->toArray() as $rma) {
            $rmaIds = [];

            foreach ($rma as $rmaPart) {
                $rmaIds[] = $rmaPart['id'];
            }

            $totalRMAQty = 0;
            $rmaItems = $this->rmaItemsRepository
                        ->findWhereIn('rma_id', $rmaIds);

            foreach ($rmaItems as $rmaItem) {
                $totalRMAQty += $rmaItem->quantity;
            }

          
            foreach ($orders as $key => $order) {
                if ($rma[0]['order_id'] == $order->id) {
                    if ($totalRMAQty == $order->total_qty_ordered) {
                        unset($orders[$key]);
                    }
                }

                if ($enableRMAForCanceledOrder) {
                    if ($order->status == "canceled") {
                        unset($orders[$key]);
                    }
                }
            }
        }

        return [
            'customerName'  => $customerName,
            'customerEmail' => $customerEmail,
            'count'         => $orders->count(),
            'orders'        => $orders->forPage($page, $perPage)
        ];
    }

    /**
     * Get Orders
     *
     * @return array
     */
    public function getOrders($orderId, $resolutions)
    {
        $productRepository = app('Webkul\Product\Repositories\ProductRepository');
        $productImageRepository = app('Webkul\Product\Repositories\ProductImageRepository');

        $order = $this->orderRepository->findOrFail($orderId);
        
        $orderItems = $this->orderItemRepository
        ->where("order_id", $order->id)
        ->where("type","!=" ,"configurable")
        ->latest()->paginate(5);
                    
        if ($resolutions == "null") {
            $enableRMAForPendingOrder = core()->getConfigData('sales.rma.setting.enable_rma_for_pending_order');

            //check the product's shipment and invoice is generated or not

            foreach ($orderItems as $orderItem) {
                $itemsId[] = $orderItem->id;
                $productImage = [];
                $productId = [];
                $product = $productRepository->find($orderItem->product_id);
                $productImage[$product->id] = ProductImage::getProductBaseImage($product);  
                $productId[] = $orderItem->product_id;
                $productImageCounts = $productImageRepository->findWhereIn('product_id',$productId)->count();              
            }

            $shipmentItems = app('Webkul\Sales\Repositories\ShipmentItemRepository');
            $shippedOrderItems = $shipmentItems->findWhereIn('order_item_id', $itemsId);

            foreach ($shippedOrderItems as $shippedOrderItem) {
                $shippedOrderItemId[] = $shippedOrderItem->order_item_id;
            }

            $invoiceItems = app('Webkul\Sales\Repositories\InvoiceItemRepository');
            $invoiceCreatedItems = $invoiceItems->findWhereIn('order_item_id', $itemsId);

            foreach ($invoiceCreatedItems as $invoiceCreatedItem) {
                $invoiceCreatedItemId[] = $invoiceCreatedItem->order_item_id;
            }

            if ($enableRMAForPendingOrder) {
                $resolutions = ['Cancel Items'];
            } else {
                $resolutions = [];
            }

            $orderStatus = ['Not Delivered'];

            if (isset($shippedOrderItemId)) {
                $resolutions = ['Cancel Items','Exchange'];
                
                if ( count($shippedOrderItemId) == count($itemsId)) {
                $orderStatus = ['Not Delivered','Delivered'];
                }
            }

            if (isset($invoiceCreatedItemId)) {
                $resolutions = ['Return','Exchange'];
            }

            if (isset($invoiceCreatedItemId) && isset($shippedOrderItemId)) {
                $resolutions = ['Return','Exchange'];
            }
        } else {
            $resolutions = [$resolutions];
        }
        return [
            'search_results' => $orderItems,
            'resolutions'    => $resolutions,
            'productImage'   => $productImage,
            'productImageCounts' => $productImageCounts
        ];       
    }
}
