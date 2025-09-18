<?php
declare(strict_types=1);

class Router {
  private array $routes = [];

  public function get(string $path, callable $h): void  { $this->routes['GET'][$path]  = $h; }
  public function post(string $path, callable $h): void { $this->routes['POST'][$path] = $h; }

  public function dispatch(string $method, string $path) {
    if (isset($this->routes[$method][$path])) {
      return $this->routes[$method][$path]();
    }
    http_response_code(404);
    echo "<h1>Not found</h1>";
    return null;
  }
}
