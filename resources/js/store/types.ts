export interface Product {
    id: string;
    sku: string;
    name: string;
    slug: string;
    description: string | null;
    price_minor: number;
    currency: string;
    category: { id: number; name: string; slug: string } | null;
    is_active?: boolean;
}

export interface OrderItem {
    sku: string;
    name: string;
    unit_price_minor: number;
    quantity: number;
    discount_minor: number;
    line_total_minor: number;
}

export interface CustomerOrder {
    id: string;
    order_number: string;
    status: string;
    status_label: string;
    currency: string;
    subtotal_minor: number;
    discount_minor: number;
    shipping_minor: number;
    total_minor: number;
    coupon_code: string | null;
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    shipping_city: string;
    shipping_address: string;
    placed_at: string;
    fulfilment_status?: string;
    fulfilment_label?: string;
    carrier?: string | null;
    tracking_number?: string | null;
    shipped_at?: string | null;
    delivered_at?: string | null;
    items: OrderItem[];
    /** Kept in this browser only, from checkout. */
    access_token?: string;
}

export interface Quote {
    currency: string;
    subtotal_minor: number;
    discount_minor: number;
    shipping_minor: number;
    total_minor: number;
    coupon_code: string | null;
}
