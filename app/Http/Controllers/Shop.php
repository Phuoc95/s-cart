<?php
#app/Http/Controller/Shop.php
namespace App\Http\Controllers;

use App\Models\ShopAttributeGroup;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use App\Models\ShopOrder;
use App\Models\ShopOrderDetail;
use App\Models\ShopOrderHistory;
use App\Models\ShopOrderStatus;
use App\Models\ShopOrderTotal;
use App\Models\ShopProduct;
use App\User;
use Cart;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

class Shop extends GeneralController
{
    public function __construct()
    {
        parent::__construct();

    }
/**
 * [index description]
 * @return [type] [description]
 */
    public function index(Request $request)
    {
        return view($this->theme . '.shop_home',
            array(
                'title'        => $this->configsGlobal['title'],
                'description'  => $this->configsGlobal['description'],
                'keyword'      => $this->configsGlobal['keyword'],
                'banners'      => $this->banners,
                'products_new' => (new ShopProduct)->getProducts($type = null, $limit = $this->configs['product_new'], $opt = null),
                'products_hot' => (new ShopProduct)->getProducts($type = 1, $limit = $this->configs['product_hot'], $opt = 'random'),
            )
        );
    }

/**
 * [productToCategory description]
 * @param  [type] $key [description]
 * @return [type]      [description]
 */
    public function productToCategory($name, $id)
    {
        $category = (new ShopCategory)->find($id);
        if ($category) {
            $products = $category->getProductsToCategory($id = $category->id, $limit = $this->configs['product_list'], $opt = 'paginate');
            return view($this->theme . '.shop_products_list',
                array(
                    'title'        => $category->name,
                    'description'  => $category->description,
                    'keyword'      => $this->configsGlobal['keyword'],
                    'categorySelf' => $category,
                    'products'     => $products,
                    'og_image'     => url($category->getImage()),
                )
            );
        } else {
            return view($this->theme . '.notfound',
                array(
                    'title'       => trans('messages.notfound'),
                    'description' => '',
                    'keyword'     => $this->configsGlobal['keyword'],
                )
            );
        }

    }

/**
 * All products
 * @param  [type] $key [description]
 * @return [type]      [description]
 */
    public function allProducts()
    {
        $products = ShopProduct::where('status', 1)
            ->sort()->paginate($this->configs['product_list']);
        if ($products) {
            return view($this->theme . '.shop_products_list',
                array(
                    'title'       => trans('language.all_product'),
                    'description' => $this->configsGlobal['description'],
                    'keyword'     => $this->configsGlobal['keyword'],
                    'products'    => $products,
                )
            );
        } else {
            return view($this->theme . '.notfound',
                array(
                    'title'       => trans('messages.notfound'),
                    'description' => '',
                    'keyword'     => $this->configsGlobal['keyword'],
                )
            );
        }

    }

/**
 * [productDetail description]
 * @param  [type] $name [description]
 * @param  [type] $id   [description]
 * @return [type]       [description]
 */
    public function productDetail($name, $id)
    {
        $product = ShopProduct::find($id);
        if ($product && $product->status && ($this->configs['product_display_out_of_stock'] || $product->stock > 0)) {
            //Update last view
            $product->view += 1;
            $product->date_lastview = date('Y-m-d H:i:s');
            $product->save();
            $arrlastView      = empty(\Cookie::get('productsLastView')) ? array() : json_decode(\Cookie::get('productsLastView'), true);
            $arrlastView[$id] = date('Y-m-d H:i:s');
            arsort($arrlastView);
            \Cookie::queue('productsLastView', json_encode($arrlastView), (86400 * 30));
            //End last viewed

            //Check product available
            return view($this->theme . '.shop_product_detail',
                array(
                    'title'              => $product->name,
                    'description'        => $product->description,
                    'keyword'            => $this->configsGlobal['keyword'],
                    'product'            => $product,
                    'attributesGroup'    => ShopAttributeGroup::all()->keyBy('id'),
                    'productsToCategory' => (new ShopCategory)->getProductsToCategory($id = $product->category_id, $limit = $this->configs['product_relation'], $opt = 'random'),
                    'og_image'           => url($product->getImage()),
                )
            );
        } else {
            return view($this->theme . '.notfound',
                array(
                    'title'       => trans('messages.notfound'),
                    'description' => '',
                    'keyword'     => $this->configsGlobal['keyword'],
                )
            );
        }

    }
    /**
     * [profile description]
     * @return [type] [description]
     */
    public function profile()
    {
        $user        = Auth::user();
        $id          = $user->id;
        $orders      = ShopOrder::with('orderTotal')->where('user_id', $id)->sort()->get();
        $statusOrder = ShopOrderStatus::pluck('name', 'id')->all();
        return view($this->theme . '.shop_profile')->with(array(
            'title'       => trans('language.my_profile'),
            'user'        => $user,
            'orders'      => $orders,
            'statusOrder' => $statusOrder,
        ));
    }

/**
 * [storeOrder description]
 * @param  Request $request [description]
 * @return [type]           [description]
 */
    public function storeOrder(Request $request)
    {
        if (Cart::count() == 0) {
            return redirect('/');
        }
        //Not allow for guest
        if (!$this->configs['shop_allow_guest'] && !Auth::user()) {
            return redirect('login');
        } //

        $messages = [
            'max'      => trans('validation.max.string'),
            'required' => trans('validation.required'),
        ];
        $v = Validator::make($request->all(), [
            'toname'   => 'required|max:100',
            'address1' => 'required|max:100',
            'address2' => 'required|max:100',
            'phone'    => 'required|regex:/^0[^0][0-9\-]{7,13}$/',
            'email'    => 'required|string|email|max:255',
        ], $messages);
        if ($v->fails()) {
            return redirect()->back()->withInput()->withErrors($v->errors());
        }

        try {
            //Process total
            $objects        = array();
            $objects[]      = (new ShopOrderTotal)->getShipping(); //module shipping
            $objects[]      = (new ShopOrderTotal)->getDiscount(); //module discount
            $objects[]      = (new ShopOrderTotal)->getReceived(); //module reveived
            $dataTotal      = ShopOrderTotal::processDataTotal($objects); //sumtotal and re-sort item total
            $subtotal       = (new ShopOrderTotal)->sumValueTotal('subtotal', $dataTotal);
            $shipping       = (new ShopOrderTotal)->sumValueTotal('shipping', $dataTotal); //sum shipping
            $discount       = (new ShopOrderTotal)->sumValueTotal('discount', $dataTotal); //sum discount
            $received       = (new ShopOrderTotal)->sumValueTotal('received', $dataTotal); //sum received
            $total          = (new ShopOrderTotal)->sumValueTotal('total', $dataTotal);
            $payment_method = empty($request->get('payment_method')) ? 'cash' : $request->get('payment_method');
            //end total
            DB::connection('mysql')->beginTransaction();
            $arrOrder['user_id'] = empty(Auth::user()->id) ? 0 : Auth::user()->id;

            $arrOrder['subtotal']        = $subtotal;
            $arrOrder['shipping']        = $shipping;
            $arrOrder['discount']        = $discount;
            $arrOrder['received']        = $received;
            $arrOrder['payment_status']  = 0;
            $arrOrder['shipping_status'] = 0;
            $arrOrder['status']          = 0;
            $arrOrder['currency']        = \Helper::currencyCode();
            $arrOrder['exchange_rate']   = \Helper::currencyRate();
            $arrOrder['total']           = $total;
            $arrOrder['balance']         = $total + $received;
            $arrOrder['toname']          = $request->get('toname');
            $arrOrder['email']           = $request->get('email');
            $arrOrder['address1']        = $request->get('address1');
            $arrOrder['address2']        = $request->get('address2');
            $arrOrder['phone']           = $request->get('phone');
            $arrOrder['payment_method']  = $payment_method;
            $arrOrder['comment']         = $request->get('comment');
            $arrOrder['created_at']      = date('Y-m-d H:i:s');

            //Insert to Order
            $orderId = ShopOrder::insertGetId($arrOrder);
            //

            //Insert order total
            ShopOrderTotal::insertTotal($dataTotal, $orderId);
            //End order total

            foreach (Cart::content() as $value) {
                $product                  = ShopProduct::find($value->id);
                $arrDetail['order_id']    = $orderId;
                $arrDetail['product_id']  = $value->id;
                $arrDetail['name']        = $value->name;
                $arrDetail['price']       = \Helper::currencyValue($value->price);
                $arrDetail['qty']         = $value->qty;
                $arrDetail['attribute']   = ($value->options->att) ? json_encode($value->options->att) : null;
                $arrDetail['sku']         = $product->sku;
                $arrDetail['total_price'] = \Helper::currencyValue($value->price) * $value->qty;
                $arrDetail['created_at']  = date('Y-m-d H:i:s');
                ShopOrderDetail::insert($arrDetail);
                //If product out of stock
                if (!$this->configs['product_buy_out_of_stock'] && $product->stock < $value->qty) {
                    return redirect('/')->with('error', trans('language.cart.over', ['item' => $product->sku]));
                } //
                $product->stock -= $value->qty;
                $product->sold += $value->qty;
                $product->save();

            }

            if (!empty(session('coupon'))) {
                $couponAllowGuest = empty($this->configs['coupon_allow_guest']) ? false : true;
                \Promocodes::apply(session('coupon'), $uID = null, $msg = 'Order #' . $orderId, $couponAllowGuest); // apply coupon
                $request->session()->forget('coupon'); //destroy coupon
            }

            //Add history
            $dataHistory = [
                'order_id' => $orderId,
                'content'  => 'New order',
                'user_id'  => empty(Auth::user()->id) ? 0 : Auth::user()->id,
                'add_date' => date('Y-m-d H:i:s'),
            ];
            ShopOrderHistory::insert($dataHistory);

            $dataItems = Cart::content();
            Cart::destroy(); // destroy cart

            DB::connection('mysql')->commit();

            //Process paypal
            if ($payment_method == 'paypal') {
                $data_payment = [];
                foreach ($dataItems as $value) {
                    $product        = ShopProduct::find($value->id);
                    $data_payment[] =
                        [
                        'name'     => $value->name,
                        'quantity' => $value->qty,
                        'price'    => \Helper::currencyValue($value->price),
                        'sku'      => $product->sku,
                    ];
                }
                $data_payment[] =
                    [
                    'name'     => 'Shipping',
                    'quantity' => 1,
                    'price'    => $shipping,
                    'sku'      => 'shipping',
                ];
                $data_payment[] =
                    [
                    'name'     => 'Discount',
                    'quantity' => 1,
                    'price'    => $discount,
                    'sku'      => 'discount',
                ];
                $data_payment['order_id'] = $orderId;
                $data_payment['currency'] = \Helper::currencyCode();
                return redirect('payment/paypal')->with('data_payment', $data_payment);
            } else {
                return $this->completeOrder($orderId);
            }

            //

        } catch (\Exception $e) {
            DB::connection('mysql')->rollBack();
            echo 'Caught exception: ', $e->getMessage(), "\n";

        }

    }

/**
 * [addToCart description]
 * @param Request $request [description]
 */
    public function addToCart(Request $request)
    {
        if (!$request->ajax()) {
            return redirect('/cart.html');
        }
        $instance   = $request->get('instance') ?? 'default';
        $id         = $request->get('id');
        $product    = ShopProduct::find($id);
        $attributes = $request->get('attributes') ?? '';
        if ($instance == 'default') {
            //Cart
            //Condition:
            //1. Active
            //2. Instock or allow order out of stock
            //3. Date availabe
            if ($product->status != 0
                && ($this->configs['product_preorder'] == 1 || $product->date_available == null || date('Y-m-d H:i:s') >= $product->date_available)
                && ($this->configs['product_buy_out_of_stock'] || $product->stock)) {
                Cart::add(
                    array(
                        'id'        => $id,
                        'name'      => $product->name,
                        'qty'       => 1,
                        'price'     => $product->getPrice($id),
                        'attribute' => $attributes,
                    )
                );
            }

        } else {
            //Wishlist or Compare...
            ${'arrID' . $instance} = array_keys(Cart::instance($instance)->content()->groupBy('id')->toArray());
            if (!in_array($id, ${'arrID' . $instance})) {
                Cart::instance($instance)->add(
                    array(
                        'id'    => $id,
                        'name'  => $product->name,
                        'qty'   => 1,
                        'price' => $product->getPrice($id),
                    )
                );
            } else {
                return response()->json(
                    [
                        'error' => 1,
                        'msg'   => trans('language.cart.exist', ['instance' => $instance]),
                    ]
                );
            }
        }

        return response()->json(
            [
                'error'      => 0,
                'count_cart' => Cart::instance($instance)->count(),
                'instance'   => $instance,
            ]
        );

    }

/**
 * [updateToCart description]
 * @param  Request $request [description]
 * @return [type]           [description]
 */
    public function updateToCart(Request $request)
    {
        if (!$request->ajax()) {
            return redirect('/cart.html');
        }
        $id      = $request->get('id');
        $rowId   = $request->get('rowId');
        $product = ShopProduct::find($id);
        $new_qty = $request->get('new_qty');
        if ($product->stock < $new_qty && !$this->configs['product_buy_out_of_stock']) {
            return response()->json(
                [
                    'error' => 1,
                    'msg'   => trans('language.cart.over', ['item' => $product->sku]),
                ]);
        } else {
            Cart::update($rowId, ($new_qty) ? $new_qty : 0);
            return response()->json(
                ['error' => 0,
                ]);
        }

    }
/**
 * [postCart description]
 * @param  Request $request [description]
 * @return [type]           [description]
 */
    public function postCart(Request $request)
    {
        $data       = $request->all();
        $product_id = $data['product_id'];
        $opt_sku    = $data['opt_sku'] ?? null;
        $attribute  = $data['attribute'] ?? null;
        $qty        = $data['qty'];
        $product    = ShopProduct::find($product_id);
        //Condition:
        //Active
        //In of stock or allow order out of stock
        //Date availabe
        if ($product->status != 0 &&
            ($this->configs['product_preorder'] == 1 || $product->date_available == null || date('Y-m-d H:i:s') >= $product->date_available) &&
            ($this->configs['product_display_out_of_stock'] || $product->stock > 0)) {
            $options = array();
            if ($opt_sku != $product->sku && $opt_sku) {
                $options['opt'] = $opt_sku;
            }
            $options['att'] = $attribute;
            Cart::add(
                array(
                    'id'      => $product_id,
                    'name'    => $product->name,
                    'qty'     => $qty,
                    'price'   => (new ShopProduct)->getPrice($product_id, $opt_sku),
                    'options' => $options,
                )
            );
        }
        return redirect('/cart.html');
    }

/**
 * [getCart description]
 * @return [type] [description]
 */
    public function getCart()
    {
//====================================================
        $objects   = array();
        $objects[] = (new ShopOrderTotal)->getShipping();
        $objects[] = (new ShopOrderTotal)->getDiscount();
        $objects[] = (new ShopOrderTotal)->getReceived();
        if (!empty(session('coupon'))) {
            $hasCoupon = true;
        } else {
            $hasCoupon = false;
        }
        return view($this->theme . '.shop_cart',
            array(
                'title'           => trans('language.cart_title'),
                'description'     => '',
                'keyword'         => '',
                'cart'            => Cart::content(),
                'dataTotal'       => ShopOrderTotal::processDataTotal($objects),
                'hasCoupon'       => $hasCoupon,
                'attributesGroup' => ShopAttributeGroup::all()->keyBy('id'),
            )
        );
    }

/**
 * [wishlist description]
 * @return [type] [description]
 */
    public function wishlist()
    {

        $wishlist = Cart::instance('wishlist')->content();
        return view($this->theme . '.shop_wishlist',
            array(
                'title'       => trans('language.wishlist'),
                'description' => '',
                'keyword'     => '',
                'wishlist'    => $wishlist,
            )
        );
    }

/**
 * [compare description]
 * @return [type] [description]
 */
    public function compare()
    {
        $compare = Cart::instance('compare')->content();
        return view($this->theme . '.shop_compare',
            array(
                'title'       => trans('language.compare'),
                'description' => '',
                'keyword'     => '',
                'compare'     => $compare,
            )
        );
    }

/**
 * [clearCart description]
 * @return [type] [description]
 */
    public function clearCart()
    {
        Cart::destroy();
        return redirect('/cart.html');
    }

/**
 * [usePromotion description]
 * @param  Request $request [description]
 * @return [type]           [description]
 */
    public function usePromotion(Request $request)
    {

        if (!$this->configs['coupon_mode']) {
            return false;
        }
        $html   = '';
        $code   = $request->get('code');
        $action = $request->get('action');
        if ($action === 'remove') {
            $request->session()->forget('coupon'); //destroy coupon
            $objects   = array();
            $objects[] = (new ShopOrderTotal)->getShipping();
            $objects[] = (new ShopOrderTotal)->getDiscount();
            $objects[] = (new ShopOrderTotal)->getReceived();
            $dataTotal = ShopOrderTotal::processDataTotal($objects);
            foreach ($dataTotal as $key => $element) {
                if ($element['value'] != 0) {
                    $html .= "<tr class='showTotal'>
                         <th>" . $element['title'] . "</th>
                        <td style='text-align: right' id='" . $element['code'] . "'>" . number_format($element['value']) . "</td>
                    </tr>";
                }

            }
            return json_encode(['html' => $html]);
        }
        $couponAllowGuest = empty($this->configs['coupon_allow_guest']) ? false : true;
        $check            = json_decode(\Promocodes::check($code, $uID = null, $couponAllowGuest), true);
        if ($check['error'] == 1) {
            $error = 1;
            if ($check['msg'] == 'error_code_not_exist') {
                $msg = trans('language.promotion.process.invalid');
            } elseif ($check['msg'] == 'error_code_cant_use') {
                $msg = trans('language.promotion.process.over');
            } elseif ($check['msg'] == 'error_code_expired_disabled') {
                $msg = trans('language.promotion.process.expire');
            } elseif ($check['msg'] == 'error_user_used') {
                $msg = trans('language.promotion.process.used');
            } elseif ($check['msg'] == 'error_uID_input') {
                $msg = trans('language.promotion.process.user_id_invalid');
            } elseif ($check['msg'] == 'error_login') {
                $msg = trans('language.promotion.process.must_login');
            } else {
                $msg = trans('language.promotion.process.undefined');
            }

        } else {
            $content = $check['content'];
            if ($content['type'] === 1) {
                //Point use in my page
                $error = 1;
                $msg   = trans('language.promotion.process.not_allow');
            } else {
                $arrType = [
                    '0' => trans('language.promotion.cash'),
                    '1' => trans('language.promotion.point'),
                    '2' => trans('language.promotion.%'),
                ];
                $error = 0;
                $msg   = trans('language.promotion.process.completed');
                $request->session()->put('coupon', $code);

                $objects   = array();
                $objects[] = (new ShopOrderTotal)->getShipping();
                $objects[] = (new ShopOrderTotal)->getDiscount();
                $objects[] = (new ShopOrderTotal)->getReceived();
                $dataTotal = ShopOrderTotal::processDataTotal($objects);
                foreach ($dataTotal as $key => $element) {
                    if ($element['value'] != 0) {
                        if ($element['code'] == 'total') {
                            $html .= "<tr class='showTotal'  style='background:#f5f3f3;font-weight: bold;'>";
                        } else {
                            $html .= "<tr class='showTotal'>";
                        }

                        $html .= "<th>" . $element['title'] . "</th>
                        <td style='text-align: right' id='" . $element['code'] . "'>" . number_format($element['value']) . "</td>
                    </tr>";
                    }

                }
            }

        }
        return json_encode(['error' => $error, 'msg' => $msg, 'html' => $html]);

    }

/**
 * Remove item from cart
 * @author lanhktc
 */
    public function removeItem($id = null)
    {
        if ($id === null) {
            return redirect('cart.html');
        }

        if (array_key_exists($id, Cart::content()->toArray())) {
            Cart::remove($id);
        }

        return redirect('cart.html');
    }

/**
 * [removeItem_wishlist description]
 * @param  [type] $id [description]
 * @return [type]     [description]
 */
    public function removeItemWishlist($id = null)
    {
        if ($id === null) {
            return redirect('wishlist.html');
        }

        if (array_key_exists($id, Cart::instance('wishlist')->content()->toArray())) {
            Cart::instance('wishlist')->remove($id);
        }

        return redirect('wishlist.html');
    }

/**
 * [removeItemCompare description]
 * @param  [type] $id [description]
 * @return [type]     [description]
 */
    public function removeItemCompare($id = null)
    {
        if ($id === null) {
            return redirect('compare.html');
        }

        if (array_key_exists($id, Cart::instance('compare')->content()->toArray())) {
            Cart::instance('compare')->remove($id);
        }

        return redirect('compare.html');
    }

/**
 * [search description]
 * @param  Request $request [description]
 * @return [type]           [description]
 */
    public function search(Request $request)
    {
        $keyword = $request->get('keyword');
        return view($this->theme . '.shop_products_list',
            array(
                'title'    => trans('language.search') . ': ' . $keyword,
                'products' => ShopProduct::getSearch($keyword),
            ));
    }

/**
 * [login description]
 * @return [type] [description]
 */
    public function login()
    {
        if (Auth::user()) {
            return Redirect::away('/');
        }
        return view($this->theme . '.shop_login',
            array(
                'title' => trans('language.login'),
            )
        );
    }
/**
 * [logout description]
 * @return [type] [description]
 */
    public function logout()
    {
        Auth::logout();
        return Redirect::away('login');
    }
/**
 * [login description]
 * @return [type] [description]
 */
    public function forgot()
    {
        if (Auth::user()) {
            return Redirect::away('/');
        }
        return view($this->theme . '.shop_forgot',
            array(
                'title' => trans('language.for_got_password'),
            )
        );
    }

    public function completeOrder($orderId)
    {
        //Send email
        try {
            $data = ShopOrder::with('details')->find($orderId)->toArray();
            Mail::send('vendor.mail.order_new', $data, function ($message) use ($orderId) {
                $message->to($this->configsGlobal['email'], $this->configsGlobal['title']);
                $message->replyTo($this->configsGlobal['email'], $this->configsGlobal['title']);
                $message->subject(trans('language.order.email.new_title') . '#' . $orderId);
            });
        } catch (\Exception $e) {
            echo 'Error send mail';
        } //
        return redirect('cart.html')->with('message', trans('language.order.success'));
    }

/**
 * [productBrand description]
 * @param  [type] $name [description]
 * @param  [type] $id   [description]
 * @return [type]       [description]
 */
    public function productBrand($name, $id)
    {
        $brand = ShopBrand::find($id);
        return view($this->theme . '.shop_products_list',
            array(
                'title'       => $brand->name,
                'description' => '',
                'keyword'     => '',
                'products'    => $brand->products()->paginate(9),
            )
        );
    }
}
