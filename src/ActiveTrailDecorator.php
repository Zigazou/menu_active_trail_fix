<?php

namespace Drupal\menu_active_trail_fix;

use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;

/**
 * Decorator for MenuActiveTrailInterface.
 *
 * This decorator provides a more tolerant active trail resolution.
 */
final class ActiveTrailDecorator implements MenuActiveTrailInterface {

  /**
   * The decorated MenuActiveTrailInterface instance.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  private MenuActiveTrailInterface $inner;

  /**
   * The current route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private RouteMatchInterface $routeMatch;

  /**
   * The menu link manager service.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  private MenuLinkManagerInterface $menuLinkManager;

  public function __construct(
    MenuActiveTrailInterface $inner,
    RouteMatchInterface $route_match,
    MenuLinkManagerInterface $menu_link_manager,
  ) {
    $this->inner = $inner;
    $this->routeMatch = $route_match;
    $this->menuLinkManager = $menu_link_manager;
  }

  /**
   * Routine locale qui résout un lien actif en étant plus tolérante.
   */
  private function resolveActiveLink(?string $menu_name = NULL): ?MenuLinkInterface {
    $route_name = $this->routeMatch->getRouteName();
    if (!$route_name) {
      return NULL;
    }

    // 1) Try with raw parameters (core behavior).
    $raw = $this->routeMatch->getRawParameters()->all();
    $links = $this->menuLinkManager->loadLinksByRoute($route_name, $raw);

    // 2) Fallback: converted parameters.
    if (!$links) {
      $converted = $this->routeMatch->getParameters()->all();
      $links = $this->menuLinkManager->loadLinksByRoute($route_name, $converted);
    }

    // 3) Fallback: keeps only variables really present in the path.
    if (!$links) {
      $route = $this->routeMatch->getRouteObject();
      if ($route) {
        $filtered = [];
        $variables = $route->compile()->getPathVariables();
        foreach ($variables as $var) {
          if ($this->routeMatch->getRawParameters()->has($var)) {
            $filtered[$var] = $this->routeMatch->getRawParameters()->get($var);
          }
          elseif ($this->routeMatch->getParameters()->has($var)) {
            $filtered[$var] = $this->routeMatch->getParameters()->get($var);
          }
        }
        if ($filtered) {
          $links = $this->menuLinkManager->loadLinksByRoute($route_name, $filtered);
        }
      }
    }

    if (!$links) {
      return NULL;
    }
    if ($menu_name && isset($links[$menu_name])) {
      return $links[$menu_name];
    }
    return reset($links);
  }

  /**
   * {@inheritdoc}
   *
   * Note: no direct delegation to inner if result is empty.
   */
  public function getActiveTrailIds($menu_name): array {
    // 1) First, try the core: faster (cache).
    $ids = $this->inner->getActiveTrailIds($menu_name);
    // Heuristic: often, when it "fails", we only have the root (or empty).
    if (!empty($ids) && count($ids) > 1) {
      return $ids;
    }

    // 2) Our tolerant resolution.
    $link = $this->resolveActiveLink($menu_name);
    if ($link instanceof MenuLinkInterface) {
      $parents = $this->menuLinkManager->getParentIds($link->getPluginId());
      // The expected order is from roots to leaves. getParentIds()
      // already returns the parent chain in the correct order.
      $trail = array_merge($parents, [$link->getPluginId()]);
      // Drupal expects an associative array key=value of plugin IDs.
      return array_combine($trail, $trail);
    }

    // 3) Last resort: core result as is.
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrail($menu_name): array {
    // Idem : si core ne trouve rien, on reconstruit via nos IDs.
    $trail = $this->inner->getActiveTrail($menu_name);
    if (!empty($trail)) {
      return $trail;
    }
    $ids = $this->getActiveTrailIds($menu_name);
    if (empty($ids)) {
      return [];
    }
    // Load definitions from IDs.
    $definitions = $this->menuLinkManager->loadLinks(array_values($ids));
    // Return the same format as core: associative array plugin_id =>
    // definition.
    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL): ?MenuLinkInterface {
    // Expose our resolution if a caller uses it directly.
    $link = $this->resolveActiveLink($menu_name);
    return $link ?? $this->inner->getActiveLink($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    $this->inner->resetCache();
  }

}
