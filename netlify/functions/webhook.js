const fetch = require('node-fetch');

const KLAVIYO_API_KEY = process.env.KLAVIYO_PRIVATE_API_KEY;
const KLAVIYO_BASE_URL = 'https://a.klaviyo.com/api';
const KLAVIYO_REVISION = '2023-10-15';

// Simple in-memory cache for idempotency (resets on cold start)
const processedOrders = new Set();

exports.handler = async (event, context) => {
  // Only allow POST
  if (event.httpMethod !== 'POST') {
    return {
      statusCode: 405,
      body: JSON.stringify({ error: 'Method not allowed' }),
    };
  }

  try {
    const payload = JSON.parse(event.body);

    // Log the payload
    console.log('WooCommerce Order Created Webhook Received:', JSON.stringify(payload, null, 2));

    // Validate payload
    if (!payload.billing || !payload.billing.email || !payload.id) {
      console.error('Invalid payload:', payload);
      return {
        statusCode: 400,
        body: JSON.stringify({ status: 'invalid_payload' }),
      };
    }

    // Check idempotency
    const orderId = payload.id;
    if (processedOrders.has(orderId)) {
      console.log(`Order ${orderId} already processed`);
      return {
        statusCode: 200,
        body: JSON.stringify({ status: 'already_processed' }),
      };
    }

    // Normalize data
    const profileData = {
      email: payload.billing.email,
      first_name: payload.billing.first_name || '',
      last_name: payload.billing.last_name || '',
      woocommerce_id: payload.customer_id || null,
    };

    const eventData = {
      order_id: orderId,
      total: parseFloat(payload.total) || 0,
      currency: payload.currency || 'USD',
      payment_method: payload.payment_method || '',
      coupons: payload.coupon_lines || [],
      items: payload.line_items || [],
      time: payload.date_created ? new Date(payload.date_created).getTime() / 1000 : Math.floor(Date.now() / 1000),
      properties: payload, // Include all extra fields
    };

    // Upsert profile
    await upsertProfile(profileData);

    // Track event
    await trackOrderEvent(eventData);

    // Mark as processed
    processedOrders.add(orderId);

    return {
      statusCode: 200,
      body: JSON.stringify({ status: 'processed' }),
    };
  } catch (error) {
    console.error('Error processing webhook:', error);
    // Still return 200 to WooCommerce
    return {
      statusCode: 200,
      body: JSON.stringify({ status: 'error_logged' }),
    };
  }
};

async function upsertProfile(profileData) {
  const payload = {
    data: {
      type: 'profile',
      attributes: {
        email: profileData.email,
        first_name: profileData.first_name,
        last_name: profileData.last_name,
        properties: {
          woocommerce_id: profileData.woocommerce_id,
        },
      },
    },
  };

  try {
    await makeApiCall('POST', '/profiles/', payload);
  } catch (error) {
    if (error.message.includes('409')) {
      console.log('Profile already exists, skipping upsert');
    } else {
      throw error;
    }
  }
}

async function trackOrderEvent(eventData) {
  const payload = {
    data: {
      type: 'event',
      attributes: {
        metric: {
          data: {
            type: 'metric',
            attributes: {
              name: 'Placed Order',
            },
          },
        },
        profile: {
          data: {
            type: 'profile',
            attributes: {
              email: eventData.properties.billing.email,
            },
          },
        },
        value: eventData.total,
        time: new Date(eventData.time * 1000).toISOString(),
        properties: {
          order_id: eventData.order_id,
          total: eventData.total,
          currency: eventData.currency,
          payment_method: eventData.payment_method,
          coupons: eventData.coupons,
          items: eventData.items,
          ...eventData.properties, // Include all extra fields
        },
      },
    },
  };

  await makeApiCall('POST', '/events/', payload);
}

async function makeApiCall(method, endpoint, payload) {
  const response = await fetch(`${KLAVIYO_BASE_URL}${endpoint}`, {
    method,
    headers: {
      'Authorization': `Klaviyo-API-Key ${KLAVIYO_API_KEY}`,
      'Revision': KLAVIYO_REVISION,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const errorText = await response.text();
    console.error(`Klaviyo API error ${response.status}:`, errorText);
    throw new Error(`Klaviyo API error: ${response.status}`);
  }

  console.log(`Klaviyo API success: ${endpoint} - ${response.status}`);
}