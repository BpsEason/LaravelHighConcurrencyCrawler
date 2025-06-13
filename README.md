# LaravelHighConcurrencyCrawler

A high-concurrency web crawler built with Laravel, capable of handling 100,000 requests per minute (1,667 QPS). This system leverages asynchronous crawling, Redis task queues, URL deduplication, MySQL storage, and Nginx + PHP-FPM for production-grade performance. Ideal for large-scale web scraping tasks with dynamic parsing rules and distributed deployment.

## Features
- **High Concurrency**: Processes up to 100,000 requests per minute with optimized PHP-FPM and Nginx configurations.
- **Asynchronous Crawling**: Uses GuzzleHTTP and Spatie Async for non-blocking HTTP requests, with configurable concurrency limits.
- **Task Management**: Laravel Queue with Redis for distributed task processing, managed by Horizon.
- **URL Deduplication**: Employs Redis sets (or optional Bloom filters) for efficient URL deduplication.
- **Dynamic Parsing**: Supports site-specific parsing rules via `crawler_rules.yaml`.
- **Database Optimization**: Eloquent ORM with connection pooling and batch inserts for MySQL.
- **Production-Ready**: Nginx + PHP-FPM stack, Dockerized for easy deployment, and Kubernetes-ready for scaling.
- **Monitoring**: Integrates Prometheus for tracking crawl/task success and failure rates.

## Architecture
- **API Layer**: Laravel with Nginx + PHP-FPM for high-performance HTTP request handling.
- **Task Queue**: Redis for task distribution and URL deduplication, with optional Bloom filter support.
- **Database**: MySQL with connection pooling, indexing, and read-write separation.
- **Workers**: Laravel Queue jobs for asynchronous crawling, using GuzzleHTTP and Spatie Async.
- **Monitoring**: Prometheus metrics exposed for crawl and task statistics, with Laravel Telescope for API insights.

## Prerequisites
- PHP 8.2+
- Composer
- Docker (for containerized deployment)
- Redis 6.0+ (with optional `redisbloom` for Bloom filters)
- MySQL 8.0+
- A commercial proxy service (e.g., Bright Data, Oxylabs) for production crawling

## Installation
1. **Clone the Repository**:
   ```bash
   git clone https://github.com/your-username/LaravelHighConcurrencyCrawler.git
   cd LaravelHighConcurrencyCrawler
   ```

2. **Install Dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Set Up Environment Variables**:
   Copy `.env.example` to `.env` and configure:
   ```bash
   cp .env.example .env
   ```
   Update key variables:
   ```
   APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   DB_HOST=mysql
   DB_DATABASE=crawler_db
   DB_USERNAME=root
   DB_PASSWORD=your_password
   REDIS_HOST=redis
   ```
   Generate application key:
   ```bash
   php artisan key:generate
   ```

4. **Initialize Database**:
   Run migrations to create tables:
   ```bash
   php artisan migrate
   ```

5. **Configure Parsing Rules**:
   Edit `config/crawler_rules.yaml` for site-specific parsing rules. Example:
   ```yaml
   sites:
     example.com:
       title_selector: "h1.product-title"
       price_selector: "span.product-price"
       description_selector: "div.product-description"
       image_selector: "img.product-image"
   ```

## Running the Application
### Local Development
1. **Start API (Nginx + PHP-FPM)**:
   Use Docker Compose for a production-like setup (see below).

2. **Start Queue Workers**:
   ```bash
   php artisan queue:work --queue=crawler_task_queue --tries=3
   ```

3. **Run Batch Task Insertion**:
   ```bash
   php artisan crawler:batch-insert-tasks
   ```

4. **Optional: Start Horizon**:
   ```bash
   php artisan horizon
   ```

### Docker Compose
1. **Build and Run**:
   ```bash
   docker-compose up -d
   ```
   This starts:
   - Nginx (port 8000)
   - PHP-FPM (API)
   - Queue workers
   - Redis
   - MySQL

2. **Access Logs**:
   ```bash
   docker-compose logs
   ```

3. **Initialize Database**:
   ```bash
   docker-compose exec api php artisan migrate
   ```

API is available at `http://localhost:8000`.

## API Usage
The API is documented via OpenAPI (integrate with Laravel Swagger if needed). Key endpoints:

1. **Submit a Crawl Task**:
   ```bash
   curl -X POST "http://localhost:8000/api/crawl" -H "Content-Type: application/json" -d '{"start_url": "https://example.com/product", "max_pages": 10}'
   ```
   Response:
   ```json
   {"message": "爬蟲任務已提交", "task_id": "uuid"}
   ```

2. **Check Task Status**:
   ```bash
   curl "http://localhost:8000/api/crawl_status/{task_id}"
   ```
   Response:
   ```json
   {"task_id": "uuid", "start_url": "https://example.com/product", "status": "running", ...}
   ```

3. **List Products**:
   ```bash
   curl "http://localhost:8000/api/products?skip=0&limit=100"
   ```
   Response:
   ```json
   [{"id": 1, "title": "Product Name", "price": 99.99, "product_url": "https://example.com/product", ...}, ...]
   ```

4. **Get Single Product**:
   ```bash
   curl "http://localhost:8000/api/products/{product_id}"
   ```
   Response:
   ```json
   {"id": 1, "title": "Product Name", "price": 99.99, "product_url": "https://example.com/product", ...}
   ```

## Performance
Optimized for 100,000 requests per minute (1,667 QPS) with:
- **API Layer**: 4 servers (8 cores, 16GB each), each with 4 PHP-FPM processes and 100 child processes.
- **Workers**: 20 servers (4 cores, 8GB each), each running 10 queue workers with 10 concurrent tasks.
- **Redis**: Redis Cluster (3 masters, 3 replicas, 4 cores, 8GB each).
- **MySQL**: 1 master, 2 replicas (8 cores, 16GB each) with read-write splitting.
- **Proxy Pool**: Commercial proxy service to avoid IP blocking.

### Estimated Throughput
- **API**: 2,000 QPS with 4 servers.
- **Database**: 2,000 write QPS, 5,000 read QPS with caching and read replicas.
- **Workers**: 2,000 tasks/second with 20 servers.
- **Redis**: 200,000 QPS with Redis Cluster.

### Bottlenecks
- Target website response time and IP blocking may limit crawling speed.
- Adjust Spatie Async concurrency (`Pool::create()->concurrency(100)`) and proxy pool based on target site policies.

## Deployment Recommendations
### Kubernetes
For production, deploy with Kubernetes:
- **API Pods**: 4 replicas, each with Nginx + PHP-FPM (4 processes).
- **Worker Pods**: 20 replicas, each with 10 queue workers.
- **Redis Cluster**: 6 nodes (3 masters, 3 replicas).
- **MySQL**: 1 master, 2 read replicas with ProxySQL for load balancing.
- **HorizontalPodAutoscaler**: Scale pods based on CPU usage or request rate.

### Monitoring
- **Prometheus**: Metrics for crawl/task success and failure rates.
- **Laravel Telescope**: Monitor API requests and queue jobs.
- **Grafana**: Visualize metrics and set up dashboards.
- **Alertmanager**: Configure alerts for high error rates or system overload.

### Proxy Pool
- Configure commercial proxies in `config/crawler.php` (e.g., Bright Data, Oxylabs).
- Implement dynamic proxy rotation and health checks.

## Testing
Use Locust for load testing:
```bash
composer require locustio/locust
locust -f locustfile.py
```
Example `locustfile.py`:
```python
from locust import HttpUser, task, between

class CrawlerUser(HttpUser):
    wait_time = between(0.1, 0.5)

    @task
    def submit_crawl(self):
        self.client.post("/api/crawl", json={"start_url": "https://example.com/product", "max_pages": 10})

    @task
    def get_status(self):
        self.client.get("/api/crawl_status/00000000-0000-0000-0000-000000000000")

    @task
    def get_products(self):
        self.client.get("/api/products?skip=0&limit=100")
```
Run to simulate 1,667 QPS:
```bash
locust --host=http://localhost:8000 --users=2000 --spawn-rate=100
```

## Contributing
Contributions are welcome! Please:
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/YourFeature`).
3. Commit changes (`git commit -m 'Add YourFeature'`).
4. Push to the branch (`git push origin feature/YourFeature`).
5. Open a Pull Request.

Report issues or suggest features via GitHub Issues.

## License
[MIT License](LICENSE)

## Acknowledgments
- Laravel for its robust framework.
- Spatie Async for asynchronous PHP processing.
- Redis and MySQL for scalable storage and queuing.
