<?php

namespace Drupal\menu_active_trail_fix;

use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;

/**
 * Tolerant decorator for active trail calculation.
 *
 * Goal: work around the regression where loadLinksByRoute() no longer
 * finds the link when default parameters are present on the route.
 *
 * @see https://www.drupal.org/project/drupal/issues/3359511
 */
final class ActiveTrailDecorator implements MenuActiveTrailInterface {

  /**
   * The decorated core service.
   */
  private MenuActiveTrailInterface $inner;

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
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL): ?MenuLinkInterface {
    // 1) Core behavior (raw parameters).
    $route_name = $this->routeMatch->getRouteName();
    if (!$route_name) {
      return $this->inner->getActiveLink($menu_name);
    }

    $raw = $this->routeMatch->getRawParameters()->all();
    $links = $this->menuLinkManager->loadLinksByRoute($route_name, $raw);

    // 2) Fallback: converted parameters (often without defaults).
    if (!$links) {
      $converted = $this->routeMatch->getParameters()->all();
      $links = $this->menuLinkManager->loadLinksByRoute($route_name, $converted);
    }

    // 3) Fallback: keep only the variables present in the path.
    if (!$links) {
      $route = $this->routeMatch->getRouteObject();
      if ($route) {
        $filtered = [];
        $variables = $route->compile()->getPathVariables();
        foreach ($variables as $var) {
          // Prefer raw values to avoid any expensive conversion.
          if ($this->routeMatch->getRawParameters()->has($var)) {
            $filtered[$var] = $this->routeMatch->getRawParameters()->get($var);
          }
          elseif ($this->routeMatch->getParameters()->has($var)) {
            $filtered[$var] = $this->routeMatch->getParameters()->get($var);
          }
        }
        if (!empty($filtered)) {
          $links = $this->menuLinkManager->loadLinksByRoute($route_name, $filtered);
        }
      }
    }

    if ($links) {
      // If a menu is specified, choose the link from that menu.
      if ($menu_name && isset($links[$menu_name])) {
        return $links[$menu_name];
      }
      // Otherwise, return the first link found (reasonable behavior).
      return reset($links);
    }

    // Last resort: fall back to core behavior as-is.
    return $this->inner->getActiveLink($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrailIds($menu_name): array {
    return $this->inner->getActiveTrailIds($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrail($menu_name): array {
    return $this->inner->getActiveTrail($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    $this->inner->resetCache();
  }

}
