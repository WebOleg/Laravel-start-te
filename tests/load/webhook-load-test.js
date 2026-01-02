/**
 * k6 Load Test Script for EMP Webhook Endpoint
 * 
 * THR-167: Create k6 load test script to simulate EMP webhook traffic
 * 
 * Run: k6 run tests/load/webhook-load-test.js
 * Run with env: k6 run -e BASE_URL=https://staging.example.com tests/load/webhook-load-test.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import crypto from 'k6/crypto';

// Custom metrics
const errorRate = new Rate('errors');
const webhookDuration = new Trend('webhook_duration', true);

// Configuration
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const API_PASSWORD = __ENV.API_PASSWORD || 'test_password';
const WEBHOOK_ENDPOINT = `${BASE_URL}/api/webhook/emp`;

// Test stages as per THR-167 requirements
export const options = {
    stages: [
        { duration: '1m', target: 50 },   // 1. Ramp to 50 RPS over 1 minute
        { duration: '3m', target: 50 },   // 2. Sustain 50 RPS for 3 minutes
        { duration: '1m', target: 100 },  // 3. Ramp to 100 RPS over 1 minute
        { duration: '3m', target: 100 },  // 4. Sustain 100 RPS for 3 minutes
        { duration: '1m', target: 0 },    // 5. Ramp down over 1 minute
    ],
    thresholds: {
        'http_req_duration': [
            'p(95)<100',  // p95 under 100ms
            'p(99)<200',  // p99 under 200ms
        ],
        'errors': ['rate<0.01'],  // Error rate under 1%
        'webhook_duration': [
            'p(95)<100',
            'p(99)<200',
        ],
    },
};

/**
 * Generate SHA1 signature matching EMP webhook format
 */
function generateSignature(uniqueId, password) {
    return crypto.sha1(uniqueId + password, 'hex');
}

/**
 * Generate random transaction ID
 */
function generateTransactionId() {
    return `tx_${Date.now()}_${Math.random().toString(36).substring(2, 15)}`;
}

/**
 * Generate SDD Sale webhook payload
 */
function generateSddSalePayload() {
    const uniqueId = generateTransactionId();
    const signature = generateSignature(uniqueId, API_PASSWORD);
    
    return {
        transaction_type: 'sdd_sale',
        unique_id: uniqueId,
        status: randomChoice(['approved', 'declined', 'pending_async']),
        amount: randomInt(100, 50000),  // 1.00 to 500.00 EUR in cents
        currency: 'EUR',
        timestamp: new Date().toISOString(),
        signature: signature,
    };
}

/**
 * Generate Chargeback webhook payload
 */
function generateChargebackPayload() {
    const uniqueId = generateTransactionId();
    const originalTxId = generateTransactionId();
    const signature = generateSignature(uniqueId, API_PASSWORD);
    
    return {
        transaction_type: 'chargeback',
        unique_id: uniqueId,
        original_transaction_unique_id: originalTxId,
        amount: randomInt(100, 50000),
        currency: 'EUR',
        reason: randomChoice(['Account closed', 'Insufficient funds', 'Disputed']),
        reason_code: randomChoice(['AC04', 'AM04', 'MD01', 'MS02', 'MS03']),
        timestamp: new Date().toISOString(),
        signature: signature,
    };
}

/**
 * Helper: Random choice from array
 */
function randomChoice(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

/**
 * Helper: Random integer between min and max
 */
function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * Main test function - executed for each virtual user iteration
 */
export default function () {
    // 80% SDD Sale, 20% Chargeback (realistic traffic distribution)
    const payload = Math.random() < 0.8 
        ? generateSddSalePayload() 
        : generateChargebackPayload();

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        timeout: '10s',
    };

    const startTime = Date.now();
    const response = http.post(WEBHOOK_ENDPOINT, JSON.stringify(payload), params);
    const duration = Date.now() - startTime;

    // Record custom metric
    webhookDuration.add(duration);

    // Validate response
    const success = check(response, {
        'status is 2xx or expected 4xx': (r) => 
            (r.status >= 200 && r.status < 300) || 
            r.status === 400 || 
            r.status === 404,  // Expected for unknown transactions
        'response time < 200ms': (r) => r.timings.duration < 200,
        'has valid JSON response': (r) => {
            try {
                JSON.parse(r.body);
                return true;
            } catch {
                return false;
            }
        },
    });

    errorRate.add(!success);

    // Small sleep to prevent overwhelming (adjust based on VU count)
    sleep(0.01);
}

/**
 * Setup function - runs once before test
 */
export function setup() {
    console.log(`Starting load test against: ${WEBHOOK_ENDPOINT}`);
    console.log(`Test duration: ~10 minutes`);
    
    // Verify endpoint is reachable
    const healthCheck = http.get(`${BASE_URL}/api/health`);
    if (healthCheck.status !== 200) {
        console.warn(`Health check returned ${healthCheck.status}`);
    }
    
    return { startTime: Date.now() };
}

/**
 * Teardown function - runs once after test
 */
export function teardown(data) {
    const duration = (Date.now() - data.startTime) / 1000;
    console.log(`Test completed in ${duration.toFixed(2)} seconds`);
}

/**
 * Custom summary handler
 */
export function handleSummary(data) {
    const summary = {
        'Total Requests': data.metrics.http_reqs.values.count,
        'Failed Requests': data.metrics.http_req_failed?.values.passes || 0,
        'Avg Response Time': `${data.metrics.http_req_duration.values.avg.toFixed(2)}ms`,
        'p95 Response Time': `${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}ms`,
        'p99 Response Time': `${data.metrics.http_req_duration.values['p(99)'].toFixed(2)}ms`,
        'Error Rate': `${(data.metrics.errors?.values.rate * 100 || 0).toFixed(2)}%`,
    };

    console.log('\n========== LOAD TEST SUMMARY ==========');
    for (const [key, value] of Object.entries(summary)) {
        console.log(`${key}: ${value}`);
    }
    console.log('========================================\n');

    return {
        'tests/load/results/webhook-load-test-summary.json': JSON.stringify(data, null, 2),
    };
}
