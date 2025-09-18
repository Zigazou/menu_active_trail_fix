<?php

namespace Drupal\menu_active_trail_fix\Menu;

use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;

/**
 * Decorator for MenuActiveTrailInterface.
 *
 * This decorator provides a more tolerant active trail resolution.
 */
class ActiveTrailDecorator implements MenuActiveTrailInterface {

  /**
   * The decorated MenuActiveTrailInterface instance.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected MenuActiveTrailInterface $inner;

  /**
   * The current route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The menu link manager service.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected MenuLinkManagerInterface $menuLinkManager;

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
   * Tries to resolve an active link for the given menu.
   */
  protected function resolveActiveLink(?string $menu_name = NULL): ?MenuLinkInterface {
    // Let core answer first.
    $core = $this->inner->getActiveLink($menu_name);
    if ($core instanceof MenuLinkInterface) {
      return $core;
    }

    $route_name = $this->routeMatch->getRouteName();
    if (!$route_name) {
      return NULL;
    }
    $route_parameters = $this->routeMatch->getRawParameters()->all() ?? [];

    // Exact parameters.
    $candidates = $this->menuLinkManager->loadLinksByRoute($route_name, $route_parameters, $menu_name);
    if (!empty($candidates)) {
      return reset($candidates) ?: NULL;
    }

    // Same route, ignore parameters (typical for Views pages).
    $candidates = $this->menuLinkManager->loadLinksByRoute($route_name, [], $menu_name);
    if (!empty($candidates)) {
      return reset($candidates) ?: NULL;
    }

    // Last resort: any link on this route, filtered to the given menu.
    if ($menu_name) {
      foreach ($this->menuLinkManager->loadLinksByRoute($route_name) as $link) {
        if ($link->getMenuName() === $menu_name && $link->isEnabled()) {
          return $link;
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Note: no direct delegation to inner if result is empty.
   */
  public function getActiveTrailIds($menu_name): array {
    // First, try the core: faster (cache).
    $ids = $this->inner->getActiveTrailIds($menu_name);

    // Heuristic: often, when it "fails", we only have the root (or empty).
    if (!empty($ids) && count($ids) > 1) {
      return $ids;
    }

    // A tolerant resolution.
    $link = $this->resolveActiveLink($menu_name);
    if ($link instanceof MenuLinkInterface) {
      $parents = $this->menuLinkManager->getParentIds($link->getPluginId());
      // The expected order is from roots to leaves. getParentIds()
      // already returns the parent chain in the correct order.
      $trail = array_merge($parents, [$link->getPluginId()]);
      // Drupal expects an associative array key=value of plugin IDs.
      return array_combine($trail, $trail);
    }

    // Last resort: core result as is.
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
