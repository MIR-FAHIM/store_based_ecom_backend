<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DeliveryAddressController;
use App\Http\Controllers\WishListController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\AttributeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OnlinePaymentController;
use App\Http\Controllers\ProductDiscountController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\RelatedProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebsiteSettingController;
use App\Http\Controllers\ShippingCostController;
use App\Http\Controllers\SMSController;
use App\Http\Controllers\BankAccountSellerController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\LoginSuccessLogController;
use App\Http\Controllers\FirebaseNotificationController;
use App\Http\Controllers\SubscriptionPackageController;
use App\Http\Controllers\SellerStoreCategoryController;

// Authentication endpoints hlw
Route::post('/auth/login', [AuthController::class, 'login'])->withoutMiddleware('token');
Route::post('/auth/login-otp', [AuthController::class, 'loginWithOtp'])->withoutMiddleware('token');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/tokens', [AuthController::class, 'listTokens']);
Route::delete('/auth/tokens/{id}', [AuthController::class, 'revokeToken']);
Route::prefix('users')->group(function () {
    Route::post('/create', [UserController::class, 'createUser'])->withoutMiddleware('token');;
    Route::post('/create-seller', [UserController::class, 'createSeller'])->withoutMiddleware('token');;
    Route::get('/check-referral-code', [UserController::class, 'checkReferralCode'])->withoutMiddleware('token');

    Route::get('/list', [UserController::class, 'listUsers']);
    Route::get('/customers', [UserController::class, 'getCustomers']);
    Route::get('/vendors', [UserController::class, 'getVendors']);
    Route::get('/delivery-men', [UserController::class, 'getDeliveryMan']);
    Route::get('/details/{id}', [UserController::class, 'getUserDetails']);
    Route::put('/update/{id}', [UserController::class, 'updateUser']);
    Route::patch('/ban/{id}', [UserController::class, 'banUser']);
    Route::patch('/unban/{id}', [UserController::class, 'unbanUser']);
    Route::delete('/delete/{id}', [UserController::class, 'deleteUser']);
    Route::delete('/delete-seller/{id}', [UserController::class, 'deleteSeller']);
});

Route::prefix('categories')->group(function () {
    Route::post('/create', [CategoryController::class, 'createCategory']);
    Route::get('/list', [CategoryController::class, 'listCategories'])->withoutMiddleware('token');
    Route::get('/category/info', [CategoryController::class, 'getCategoryInfo'])->withoutMiddleware('token');
    Route::get('/with-children', [CategoryController::class, 'getCategoryWithAllChildren'])->withoutMiddleware('token');
    Route::get('/details/{id}', [CategoryController::class, 'getCategoryDetails'])->withoutMiddleware('token');
    Route::get('/children/{id}', [CategoryController::class, 'getCategoryChildren'])->withoutMiddleware('token');
    Route::put('/update/{id}', [CategoryController::class, 'updateCategory']);

    Route::delete('/delete/{id}', [CategoryController::class, 'deleteCategory']);
});

Route::prefix('public/stores')->group(function () {
    Route::get('/{slug}/categories', [CategoryController::class, 'publicStoreCategories'])->withoutMiddleware('token');
});

Route::prefix('brands')->group(function () {
    Route::post('/create', [BrandController::class, 'createBrand']);
    Route::get('/list', [BrandController::class, 'listBrands']);
    Route::get('/details/{id}', [BrandController::class, 'getBrandDetails']);
    Route::put('/update/{id}', [BrandController::class, 'updateBrand']);
    Route::delete('/delete/{id}', [BrandController::class, 'deleteBrand']);
});

Route::prefix('subscription-packages')->group(function () {
    Route::get('/', [SubscriptionPackageController::class, 'index'])->withoutMiddleware('token');
    Route::get('/slug/{slug}', [SubscriptionPackageController::class, 'detailsBySlug'])->withoutMiddleware('token');
    Route::get('/{id}', [SubscriptionPackageController::class, 'details'])->withoutMiddleware('token');
    Route::post('/create', [SubscriptionPackageController::class, 'create']);
    Route::put('/update/{id}', [SubscriptionPackageController::class, 'update']);
    Route::patch('/inactive/{id}', [SubscriptionPackageController::class, 'inactive']);
    Route::delete('/delete/{id}', [SubscriptionPackageController::class, 'delete']);
});

Route::prefix('products')->group(function () {
    Route::post('/create', [ProductController::class, 'createProduct']);
    Route::post('/duplicate/{id}', [ProductController::class, 'duplicateProductById']);
    Route::get('/seller-featured-by-product', [ProductController::class, 'getSellerFeaturedByProduct'])->withoutMiddleware('token');
    Route::post('/images/upload/{productId}', [ProductController::class, 'productImageUpload']);
    Route::get('/images/{productId}', [ProductImageController::class, 'getProductImages'])->withoutMiddleware('token');
    Route::get('/list', [ProductController::class, 'listProducts'])->withoutMiddleware('token');;
    Route::get('/admin/list', [ProductController::class, 'listProductsForAdmin'])->withoutMiddleware('token');;
    Route::get('/list/inactive', [ProductController::class, 'listInactiveProducts'])->withoutMiddleware('token');;
    Route::get('/category/wise', [ProductController::class, 'listCategoryProducts'])->withoutMiddleware('token');;
    Route::get('/list/featured', [ProductController::class, 'listFeaturedProducts'])->withoutMiddleware('token');;
    Route::get('/list/today-deal', [ProductController::class, 'listTodayDealProducts'])->withoutMiddleware('token');;
    Route::get('/list/stock-out', [ProductController::class, 'listStockOutProducts'])->withoutMiddleware('token');
    Route::get('/brand/{brandId}', [ProductController::class, 'getProductsByBrand'])->withoutMiddleware('token');
    Route::get('/details/{identifier}', [ProductController::class, 'getProductDetails'])->withoutMiddleware('token');;
    Route::post('/update/{id}', [ProductController::class, 'updateProduct']);
    Route::delete('/delete/{id}', [ProductController::class, 'deleteProduct']);
    // Images
    Route::post('/images/add/{id}', [ProductController::class, 'addProductImage']);
    Route::delete('/images/delete/{imageId}', [ProductController::class, 'deleteProductImage']);
});


Route::prefix('shops')->group(function () {
    Route::post('/create', [ShopController::class, 'createShop']);
    Route::get('/list', [ShopController::class, 'listShops'])->withoutMiddleware('token');
    Route::get('/details/{id}', [ShopController::class, 'getShopDetails'])->withoutMiddleware('token');;
    Route::get('/products/{id}', [ShopController::class, 'getShopProducts'])->withoutMiddleware('token');;
    Route::post('/update/{id}', [ShopController::class, 'updateShop']);
    Route::patch('/status/{id}', [ShopController::class, 'updateShopStatus']);
    Route::delete('/delete/{id}', [ShopController::class, 'deleteShop']);
});

Route::prefix('stores')->group(function () {
    Route::get('/{storeId}/subscription', [SubscriptionPackageController::class, 'storeCurrentSubscription']);
    Route::post('/{storeId}/subscription/subscribe', [SubscriptionPackageController::class, 'subscribe']);
});

Route::prefix('seller/stores/{storeId}/categories')->group(function () {
    Route::get('/marketplace', [SellerStoreCategoryController::class, 'marketplace']);
    Route::post('/toggle', [SellerStoreCategoryController::class, 'toggle']);
    Route::post('/sync', [SellerStoreCategoryController::class, 'sync']);
});



Route::prefix('carts')->group(function () {
    Route::get('/active/{userId}', [CartController::class, 'getActiveCart']);

    Route::post('/items/add', [CartController::class, 'addItemToCart']);
    Route::put('/items/update/{itemId}', [CartController::class, 'updateCartItemQty']);
    Route::delete('/items/delete/{itemId}', [CartController::class, 'removeCartItem']);

    Route::delete('/clear/{userId}', [CartController::class, 'clearCart']);
});





Route::prefix('orders')->group(function () {
    Route::post('/checkout', [OrderController::class, 'checkout']);

    Route::get('/list/{userId}', [OrderController::class, 'listOrdersByUser']);
    Route::get('/all/orders', [OrderController::class, 'allOrders']);
    Route::get('/orderstatus', [OrderStatusController::class, 'listOrderStatuses']);

    // Completed orders
    Route::get('/completed', [OrderController::class, 'completedOrders']);
    Route::get('/completed/{userId}', [OrderController::class, 'completedOrdersByUser']);

    // Shop orders (via shops.user_id -> order_items.shop_id)
    Route::get('/shop/{userId}', [OrderController::class, 'listOrdersByShop']);
    Route::get('/shop/{shopId}/check/{orderId}', [OrderController::class, 'checkShopOrder']);

    Route::get('/details/{id}', [OrderController::class, 'getOrderDetails']);

    Route::patch('/inactive/{id}', [OrderController::class, 'inactiveOrder']);
    Route::patch('/status/{id}', [OrderController::class, 'updateOrderStatus']);

    // Item status update (for vendor/admin workflows)
    Route::patch('/item/status/{id}', [OrderController::class, 'updateOrderItemStatus']);
});

Route::prefix('addresses')->group(function () {
    Route::post('/add', [DeliveryAddressController::class, 'addDeliveryAddress']);
    Route::get('/user/{userId}', [DeliveryAddressController::class, 'getAddressByUser']);
  
    Route::delete('/delete/{id}', [DeliveryAddressController::class, 'deleteAddress']);
    Route::patch('/inactive/{id}', [DeliveryAddressController::class, 'inactiveAddress']);
    Route::put('/update/{id}', [DeliveryAddressController::class, 'updateAddress']);
});

Route::prefix('bank-accounts')->group(function () {
    Route::post('/add', [BankAccountSellerController::class, 'addBankAccount']);
    Route::get('/user/{userId}', [BankAccountSellerController::class, 'getAccountByUserId']);
});

Route::prefix('locations')->group(function () {
    Route::get('/divisions', [DeliveryAddressController::class, 'getDivisions']);
    Route::get('/districts/{divisionId}', [DeliveryAddressController::class, 'getDistrictsByDivision']);
});


// Wishlist endpoints
Route::prefix('wishlists')->group(function () {
    Route::post('/add', [WishListController::class, 'addWishProduct']);
    Route::get('/list/{userId}', [WishListController::class, 'getWishList']);
    Route::delete('/delete/{id}', [WishListController::class, 'deleteWishedProduct']);
});

// Related products endpoints
Route::prefix('related-products')->group(function () {
    Route::post('/add', [RelatedProductController::class, 'addRelatedProduct']);
    Route::get('/list/{productId}', [RelatedProductController::class, 'getRelatedProduct'])->withoutMiddleware('token');
    Route::delete('/remove/{id}', [RelatedProductController::class, 'remove']);
});

// Review endpoints
Route::prefix('reviews')->group(function () {
    Route::post('/add', [ReviewController::class, 'addReview']);
    Route::get('/list', [ReviewController::class, 'getAllReview']);
    Route::get('/product/{productId}', [ReviewController::class, 'getReviewByProduct'])->withoutMiddleware('token');
    Route::get('/user/{userId}', [ReviewController::class, 'getReviewByUser']);
    Route::put('/update-by-user/{id}', [ReviewController::class, 'updateReviewByUser']);
    Route::delete('/remove/{id}', [ReviewController::class, 'removeReview']);
});


// Banner endpoints
Route::prefix('banners')->group(function () {
    Route::post('/add', [BannerController::class, 'addBanner']);
    Route::put('/update/{id}', [BannerController::class, 'updateBanner']);
    Route::get('/list', [BannerController::class, 'listBanners'])->withoutMiddleware('token');
    Route::get('/active', [BannerController::class, 'getActiveBanner'])->withoutMiddleware('token');
    Route::delete('/remove/{id}', [BannerController::class, 'removeBanner']);
});


Route::prefix('attributes')->group(function () {
    Route::post('/create', [AttributeController::class, 'addAttribute']);
    Route::get('/list', [AttributeController::class, 'getAttributes']);
    Route::get('/details/{id}', [AttributeController::class, 'getAttributeWithValues']);
    Route::put('/update/{id}', [AttributeController::class, 'updateAttribute']);
    Route::delete('/delete/{id}', [AttributeController::class, 'deleteAttribute']);

    // Attribute Values
    Route::post('/values/create', [AttributeController::class, 'addAttributeValue']);
    Route::put('/values/update/{id}', [AttributeController::class, 'updateAttributeValue']);
    Route::delete('/values/delete/{id}', [AttributeController::class, 'deleteAttributeValue']);
});

Route::prefix('product-attributes')->group(function () {
    Route::post('/create', [ProductAttributeController::class, 'create']);
    Route::get('/list', [ProductAttributeController::class, 'list']);
    Route::get('/details/{id}', [ProductAttributeController::class, 'details']);
    Route::put('/update/{id}', [ProductAttributeController::class, 'update']);
    Route::delete('/delete/{id}', [ProductAttributeController::class, 'delete']);
});

Route::prefix('reports')->group(function () {
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/shop/{userId}', [ReportController::class, 'shopReportByUser']);
    Route::get('/shop/sales/{shopId}', [ReportController::class, 'shopSalesReport']);
    Route::get('/orders/monthly', [ReportController::class, 'orderReportMonthly']);
    Route::get('/today', [ReportController::class, 'todayReport']);
    Route::get('/login-success', [LoginSuccessLogController::class, 'report']);
});

Route::prefix('product-discounts')->group(function () {
    Route::post('/create', [ProductDiscountController::class, 'create']);
    Route::get('/list', [ProductDiscountController::class, 'list']);
    Route::get('/details/{id}', [ProductDiscountController::class, 'details']);
    Route::put('/update/{id}', [ProductDiscountController::class, 'update']);
    Route::delete('/delete/{id}', [ProductDiscountController::class, 'delete']);
});

// Uploads
Route::prefix('uploads')->group(function () {
    Route::post('/image', [UploadController::class, 'uploadImage']);
    Route::get('/list', [UploadController::class, 'listUploads']);
    Route::get('/{id}', [UploadController::class, 'getUpload']);
    Route::delete('/{id}', [UploadController::class, 'deleteUpload']);
});

Route::prefix('deliveries')->group(function () {
    Route::post('/assign', [DeliveryController::class, 'assignDeliveryMan']);
    Route::post('/unassign', [DeliveryController::class, 'unassignDeliveryMan']);
    Route::get('/all/{deliveryManId}', [DeliveryController::class, 'getAllOrderByDeliveryMan']);
    Route::get('/delivered/{deliveryManId}', [DeliveryController::class, 'getDeliveredDelivery']);
    Route::get('/assigned/{deliveryManId}', [DeliveryController::class, 'getAssignedDelivery']);
    Route::get('/completed/{deliveryManId}', [DeliveryController::class, 'getCompletedDelivery']);
    Route::get('/report/{deliveryManId}', [DeliveryController::class, 'getDeliveryManReport']);
});

Route::prefix('transactions')->group(function () {
    Route::get('/credit', [TransactionController::class, 'creditTransaction']);
    Route::get('/debit', [TransactionController::class, 'debitTransaction']);
    Route::get('/report', [TransactionController::class, 'transactionReport']);
    Route::post('/settle/{vendorId}', [TransactionController::class, 'settleAmount']);
});

Route::prefix('website-settings')->group(function () {
    Route::post('/logo', [WebsiteSettingController::class, 'addWebsiteLogo']);
    Route::post('/add', [WebsiteSettingController::class, 'addWebsiteSetting']);
    Route::get('/logo', [WebsiteSettingController::class, 'getLogo'])->withoutMiddleware('token');
    Route::get('/website', [WebsiteSettingController::class, 'getWebsiteSetting'])->withoutMiddleware('token');
});
Route::prefix('shipping-costs')->group(function () {
    Route::post('/set', [ShippingCostController::class, 'setShippingCost']);
    Route::get('/get', [ShippingCostController::class, 'getShippingCost']);
});

Route::prefix('sms')->group(function () {
    Route::post('/send', [SMSController::class, 'sendSms'])->withoutMiddleware('token');;
    Route::post('/verify', [SMSController::class, 'verifyOtp'])->withoutMiddleware('token');;
});

Route::prefix('order-statuses')->group(function () {
    Route::get('/list', [OrderStatusController::class, 'list']);
});

Route::prefix('error-logs')->group(function () {
    Route::get('/product-create', [ErrorLogController::class, 'listProductCreateErrorLogs']);
});

Route::prefix('firebase')->group(function () {
    Route::post('/test-push', [FirebaseNotificationController::class, 'testPush']);
});



Route::prefix('payments')->group(function () {
    Route::post('/aamarpay/initiate', [OnlinePaymentController::class, 'initiate']);
    Route::post('/aamarpay/success', [OnlinePaymentController::class, 'success'])->withoutMiddleware([ApiTokenAuth::class, 'token']);
    Route::post('/aamarpay/fail', [OnlinePaymentController::class, 'fail'])->withoutMiddleware([ApiTokenAuth::class, 'token']);
    Route::post('/aamarpay/cancel', [OnlinePaymentController::class, 'cancel'])->withoutMiddleware([ApiTokenAuth::class, 'token']);
});
