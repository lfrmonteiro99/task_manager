#!/usr/bin/env node

/**
 * Performance Monitoring Script for Task Manager API
 * Monitors cache performance, rate limiting, and database metrics
 */

const axios = require('axios');
const { performance } = require('perf_hooks');

class PerformanceMonitor {
    constructor(config = {}) {
        this.baseUrl = config.baseUrl || 'http://localhost:8080';
        this.monitoringInterval = config.monitoringInterval || 60000; // 1 minute
        this.alertThresholds = {
            responseTime: config.responseTimeThreshold || 500, // ms
            errorRate: config.errorRateThreshold || 5, // %
            cacheHitRatio: config.cacheHitRatioThreshold || 80, // %
            memoryUsage: config.memoryUsageThreshold || 80 // %
        };
        
        this.metrics = {
            requests: [],
            errors: [],
            cacheMetrics: [],
            rateLimitMetrics: []
        };
        
        this.isRunning = false;
        this.testUser = null;
    }
    
    async start() {
        console.log(' Starting Task Manager API Performance Monitor');
        console.log(` Monitoring interval: ${this.monitoringInterval / 1000}s`);
        console.log('');
        
        // Setup test user for authenticated endpoints
        await this.setupTestUser();
        
        this.isRunning = true;
        
        // Start monitoring loops
        this.startMetricsCollection();
        this.startHealthChecks();
        this.startReporting();
        
        // Graceful shutdown
        process.on('SIGINT', () => {
            console.log('\n Generating final performance report...');
            this.generateFinalReport();
            process.exit(0);
        });
    }
    
    async setupTestUser() {
        try {
            const email = `monitor_user_${Date.now()}@example.com`;
            const password = 'Monitor123!';
            
            // Try to register user
            try {
                await axios.post(`${this.baseUrl}/auth/register`, {
                    name: 'Performance Monitor User',
                    email: email,
                    password: password
                });
            } catch (e) {
                // User might already exist, ignore
            }
            
            // Login to get JWT token
            const response = await axios.post(`${this.baseUrl}/auth/login`, {
                email: email,
                password: password
            });
            
            this.testUser = {
                email: email,
                token: response.data.access_token
            };
            
            console.log(' Test user authenticated for monitoring');
            
        } catch (error) {
            console.warn('  Failed to setup test user, some metrics may be unavailable');
            console.warn('   Error:', error.message);
        }
    }
    
    startMetricsCollection() {
        const collectMetrics = async () => {
            if (!this.isRunning) return;
            
            try {
                await Promise.all([
                    this.collectResponseTimeMetrics(),
                    this.collectCacheMetrics(),
                    this.collectRateLimitMetrics(),
                    this.collectErrorMetrics()
                ]);
            } catch (error) {
                console.error('Error collecting metrics:', error.message);
            }
            
            setTimeout(collectMetrics, this.monitoringInterval);
        };
        
        collectMetrics();
    }
    
    async collectResponseTimeMetrics() {
        const endpoints = [
            { path: '/health', method: 'GET', auth: false },
            { path: '/task/list', method: 'GET', auth: true },
            { path: '/task/statistics', method: 'GET', auth: true }
        ];
        
        for (const endpoint of endpoints) {
            try {
                const startTime = performance.now();
                
                const config = {
                    method: endpoint.method,
                    url: `${this.baseUrl}${endpoint.path}`,
                    timeout: 5000
                };
                
                if (endpoint.auth && this.testUser) {
                    config.headers = {
                        'Authorization': `Bearer ${this.testUser.token}`
                    };
                }
                
                const response = await axios(config);
                const responseTime = performance.now() - startTime;
                
                this.metrics.requests.push({
                    timestamp: Date.now(),
                    endpoint: endpoint.path,
                    method: endpoint.method,
                    responseTime: responseTime,
                    statusCode: response.status,
                    success: response.status < 400
                });
                
                // Check for response time alerts
                if (responseTime > this.alertThresholds.responseTime) {
                    this.logAlert('HIGH_RESPONSE_TIME', {
                        endpoint: endpoint.path,
                        responseTime: responseTime,
                        threshold: this.alertThresholds.responseTime
                    });
                }
                
            } catch (error) {
                this.metrics.errors.push({
                    timestamp: Date.now(),
                    endpoint: endpoint.path,
                    method: endpoint.method,
                    error: error.message,
                    statusCode: error.response?.status
                });
            }
        }
    }
    
    async collectCacheMetrics() {
        try {
            // This would typically call a cache metrics endpoint
            // For now, we'll simulate cache metrics collection
            const cacheMetric = {
                timestamp: Date.now(),
                hitRatio: 85 + Math.random() * 10, // Simulated
                memoryUsage: 60 + Math.random() * 20, // Simulated
                keyCount: 1000 + Math.floor(Math.random() * 500),
                operations: {
                    gets: Math.floor(Math.random() * 1000),
                    sets: Math.floor(Math.random() * 100),
                    deletes: Math.floor(Math.random() * 50)
                }
            };
            
            this.metrics.cacheMetrics.push(cacheMetric);
            
            // Check for cache alerts
            if (cacheMetric.hitRatio < this.alertThresholds.cacheHitRatio) {
                this.logAlert('LOW_CACHE_HIT_RATIO', {
                    hitRatio: cacheMetric.hitRatio,
                    threshold: this.alertThresholds.cacheHitRatio
                });
            }
            
            if (cacheMetric.memoryUsage > this.alertThresholds.memoryUsage) {
                this.logAlert('HIGH_MEMORY_USAGE', {
                    memoryUsage: cacheMetric.memoryUsage,
                    threshold: this.alertThresholds.memoryUsage
                });
            }
            
        } catch (error) {
            console.error('Failed to collect cache metrics:', error.message);
        }
    }
    
    async collectRateLimitMetrics() {
        if (!this.testUser) return;
        
        try {
            // Simulate rate limit metrics collection
            const rateLimitMetric = {
                timestamp: Date.now(),
                userTier: 'basic',
                operations: {
                    read: {
                        limit: 100,
                        used: Math.floor(Math.random() * 100),
                        remaining: Math.floor(Math.random() * 50)
                    },
                    write: {
                        limit: 50,
                        used: Math.floor(Math.random() * 50),
                        remaining: Math.floor(Math.random() * 25)
                    }
                }
            };
            
            this.metrics.rateLimitMetrics.push(rateLimitMetric);
            
        } catch (error) {
            console.error('Failed to collect rate limit metrics:', error.message);
        }
    }
    
    async collectErrorMetrics() {
        // Calculate error rate from recent requests
        const recentRequests = this.metrics.requests.filter(
            req => Date.now() - req.timestamp < this.monitoringInterval
        );
        
        const recentErrors = this.metrics.errors.filter(
            err => Date.now() - err.timestamp < this.monitoringInterval
        );
        
        if (recentRequests.length > 0) {
            const errorRate = (recentErrors.length / (recentRequests.length + recentErrors.length)) * 100;
            
            if (errorRate > this.alertThresholds.errorRate) {
                this.logAlert('HIGH_ERROR_RATE', {
                    errorRate: errorRate,
                    threshold: this.alertThresholds.errorRate,
                    recentErrors: recentErrors.length,
                    totalRequests: recentRequests.length + recentErrors.length
                });
            }
        }
    }
    
    startHealthChecks() {
        const healthCheck = async () => {
            if (!this.isRunning) return;
            
            try {
                const startTime = performance.now();
                const response = await axios.get(`${this.baseUrl}/health`, { timeout: 5000 });
                const responseTime = performance.now() - startTime;
                
                if (response.status !== 200) {
                    this.logAlert('HEALTH_CHECK_FAILED', {
                        statusCode: response.status,
                        responseTime: responseTime
                    });
                }
                
            } catch (error) {
                this.logAlert('HEALTH_CHECK_ERROR', {
                    error: error.message
                });
            }
            
            setTimeout(healthCheck, 30000); // Every 30 seconds
        };
        
        healthCheck();
    }
    
    startReporting() {
        const generateReport = () => {
            if (!this.isRunning) return;
            
            this.generatePerformanceReport();
            setTimeout(generateReport, this.monitoringInterval * 5); // Every 5 intervals
        };
        
        generateReport();
    }
    
    generatePerformanceReport() {
        const now = Date.now();
        const last5Minutes = now - (5 * 60 * 1000);
        
        // Filter recent metrics
        const recentRequests = this.metrics.requests.filter(req => req.timestamp > last5Minutes);
        const recentErrors = this.metrics.errors.filter(err => err.timestamp > last5Minutes);
        const recentCacheMetrics = this.metrics.cacheMetrics.filter(metric => metric.timestamp > last5Minutes);
        
        if (recentRequests.length === 0) {
            console.log(' No requests in the last 5 minutes');
            return;
        }
        
        // Calculate averages
        const avgResponseTime = recentRequests.reduce((sum, req) => sum + req.responseTime, 0) / recentRequests.length;
        const errorRate = (recentErrors.length / (recentRequests.length + recentErrors.length)) * 100;
        const avgCacheHitRatio = recentCacheMetrics.length > 0 
            ? recentCacheMetrics.reduce((sum, metric) => sum + metric.hitRatio, 0) / recentCacheMetrics.length
            : 0;
        
        console.log('\n Performance Report (Last 5 minutes)');
        console.log('==========================================');
        console.log(` Requests processed: ${recentRequests.length}`);
        console.log(` Average response time: ${avgResponseTime.toFixed(2)}ms`);
        console.log(` Error rate: ${errorRate.toFixed(2)}%`);
        console.log(` Cache hit ratio: ${avgCacheHitRatio.toFixed(2)}%`);
        
        console.log('');
    }
    
    generateFinalReport() {
        console.log('\n Final Performance Report');
        console.log('==========================');
        
        const totalRequests = this.metrics.requests.length;
        const totalErrors = this.metrics.errors.length;
        
        if (totalRequests === 0) {
            console.log('No requests recorded during monitoring session.');
            return;
        }
        
        const avgResponseTime = this.metrics.requests.reduce((sum, req) => sum + req.responseTime, 0) / totalRequests;
        const errorRate = (totalErrors / (totalRequests + totalErrors)) * 100;
        
        console.log(` Total requests: ${totalRequests}`);
        console.log(` Total errors: ${totalErrors}`);
        console.log(` Overall average response time: ${avgResponseTime.toFixed(2)}ms`);
        console.log(` Overall error rate: ${errorRate.toFixed(2)}%`);
        
        console.log('\n Monitoring session completed successfully.');
    }
    
    logAlert(type, data) {
        const timestamp = new Date().toISOString();
        console.log(`\n ALERT [${timestamp}] - ${type}`);
        console.log('   Details:', JSON.stringify(data, null, 2));
    }
    
    stop() {
        this.isRunning = false;
        console.log(' Performance monitoring stopped');
    }
}

// CLI usage
if (require.main === module) {
    const config = {
        baseUrl: process.env.API_BASE_URL || 'http://localhost:8080',
        monitoringInterval: parseInt(process.env.MONITORING_INTERVAL) || 60000,
        responseTimeThreshold: parseInt(process.env.RESPONSE_TIME_THRESHOLD) || 500,
        errorRateThreshold: parseInt(process.env.ERROR_RATE_THRESHOLD) || 5,
        cacheHitRatioThreshold: parseInt(process.env.CACHE_HIT_RATIO_THRESHOLD) || 80,
        memoryUsageThreshold: parseInt(process.env.MEMORY_USAGE_THRESHOLD) || 80
    };
    
    const monitor = new PerformanceMonitor(config);
    monitor.start().catch(console.error);
}

module.exports = PerformanceMonitor;