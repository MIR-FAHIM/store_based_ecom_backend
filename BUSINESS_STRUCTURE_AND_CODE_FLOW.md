# Business Structure & Code Flow Reference

This document outlines the core business logic, entity workflows, table relationships, and architectural patterns of the **Store-Based E-Commerce Backend**. Use this as an authoritative guide for future development, feature extensions, and system integrations.

---

## 1. Authentication & User System

### Authentication Model
- **Custom Bearer Token System**: Uses custom middleware (`App\Http\Middleware\ApiTokenAuth`).
- **Token Hashing**: Tokens are SHA-256 hashed and stored in the `api_tokens` table.
- **Request Context**: The authenticated user object is attached to the request attributes as `$request->attributes->get('api_user')`.

### User Types (`users.user_type`)
1. **`customer`**: Standard buyer account.
2. **`seller` / `vendor`**: Shop owner selling products on the platform.
3. **`delivery_man`**: Delivery agent assigned to fulfill orders.
4. **`admin`**: System administrator with full management access.

---

## 2. Core Business Workflows

### A. Delivery Man Creation & Assignment Flow 🚚
> **Key Rule**: A Delivery Man is created in a 2-step process where a base `User` record is created first, followed by a detailed `DeliveryMan` profile. The resulting `DeliveryMan` ID and `User` ID are linked and utilized in subsequent assignment workflows.

1. **Step 1: Base User Account Creation**
   - API: `POST /api/users/add-delivery-man` or `POST /api/users/create-delivery-man`
   - Creates a record in the `users` table with `user_type = 'delivery_man'`.
   - Required input: `name`, `mobile` (or `phone`), `shop_id` (or `store_id`).
   - Auto-generates fallback email (`delivery_{phone}@store.com`) and default password (`password123`) if not explicitly provided.

2. **Step 2: DeliveryMan Profile Creation**
   - Creates a record in the `delivery_men` table linked via `user_id` and `store_id`.
   - Records specific metadata: `mobile`, `emergency_contact`, `father_name`, `father_contact`, `type` (`in_house`, `freelance`), `earning`, `status`, `address`, `is_verified`, `note`.

3. **Step 3: Order Assignment Flow**
   - Orders are assigned to a Delivery Man via `assign_delivery_men` table (`delivery_man_id`, `order_id`, `status`, `note`).
   - Subsequent APIs use `delivery_man_id` to query active deliveries, calculate delivery earnings, and track order fulfillment.

```
[ Frontend Request: name, mobile, shop_id ]
                    │
                    ▼
   Create User (users table, user_type='delivery_man')
                    │
                    ▼  (Returns user_id)
   Create DeliveryMan Profile (delivery_men table)
        ├── user_id
        ├── store_id
        ├── mobile, father_name, emergency_contact
        └── type, status, earning
                    │
                    ▼
  [ Ready for Order Assignment via assign_delivery_men ]
```

---

### B. Seller & Shop Creation Flow 🏬
1. **User Registration**: Create account in `users` table with `user_type = 'seller'`.
2. **Shop Initialization**: Automatically create a entry in `shops` table linked via `shops.user_id = user.id`.
3. **Store Subscriptions**: Shops subscribe to `subscription_packages` producing `store_subscriptions` records which control store product catalog limits.

---

### C. Multi-Vendor Order & Shop Filtering Flow 🛒
1. **Master Order**: Customer places an order -> Creates entry in `orders` (`user_id` = customer ID).
2. **Shop Items Split**: Purchased items are stored in `order_items`, with each item linked to its respective shop (`order_items.shop_id`).
3. **Shop-Specific Order Queries**:
   - API: `GET /api/orders/shop/{shopId}/user/{userId}`
   - API: `GET /api/orders/user-orders-by-shop?shop_id=X&user_id=Y`
   - Filters orders by customer `user_id` AND `whereHas('items', shop_id = $shopId)`.

---

### D. Online Payment Gateway Lifecycle (aamarPay Integration) 💳
1. **Initiation**: `POST /api/payments/aamarpay/initiate` creates an `OnlinePayment` record (`status = 'initiated'`).
2. **Callback Handling**:
   - **Success**: Gateway callback updates `OnlinePayment` `status = 'success'` and updates related `Order` / `StoreSubscription` / `MediaResourceOrder` to `paid` or `active`.
   - **Failure / Cancel**: Updates `OnlinePayment` status to `failed` or `cancelled`.
3. **Reporting & Dashboard**:
   - `GET /api/payments/online/report`: Aggregates total payment counts, total volume, success/failed counts, today's metrics, and month-to-date metrics.
   - `GET /api/payments/online/list`: Paginated transaction list with status, search, and date filters.

---

### E. Product Click Analytics & Today Report 📈
1. **Product Detail View**:
   - API: `GET /api/products/details/{identifier}`
   - Automatically executes `$product->increment('click_count')` every time product details are fetched.
2. **Dashboard Summary**:
   - API: `GET /api/reports/today`
   - Sums total `click_count` across all products under the `'product_clicked'` metric.

---

## 3. Standard Response Format Across Controllers

All API controllers use consistent JSON response structures:

### Success Response (`200 OK` / `201 Created`)
```json
{
  "status": "success",
  "message": "Operation completed successfully",
  "data": { ... }
}
```

### Failure / Validation Error (`400 Bad Request` / `422 Unprocessable Entity`)
```json
{
  "status": "failed",
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error description message"]
  }
}
```

---

## 4. Summary of Key Tables & Foreign Key Mappings

| Table Name | Primary Purpose | Key Foreign Keys |
|---|---|---|
| `users` | All system accounts | `referred_by` -> `users.id` |
| `shops` | Seller storefronts | `user_id` -> `users.id` |
| `delivery_men` | Delivery agent details | `user_id` -> `users.id`, `store_id` -> `shops.id` |
| `assign_delivery_men` | Order delivery tracking | `delivery_man_id` -> `users.id`, `order_id` -> `orders.id` |
| `orders` | Master customer orders | `user_id` -> `users.id` |
| `order_items` | Individual line items | `order_id` -> `orders.id`, `shop_id` -> `shops.id`, `product_id` -> `products.id` |
| `products` | Product catalog | `user_id` -> `users.id`, `shop_id` -> `shops.id`, `category_id` -> `categories.id` |
| `store_subscriptions` | Shop package plans | `store_id` -> `shops.id`, `subscription_package_id` -> `subscription_packages.id` |
| `online_payments` | Gateway transactions | `user_id`, `store_id`, `order_id`, `store_subscription_id` |
