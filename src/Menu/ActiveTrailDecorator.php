<?php

declare(strict_types=1);

namespace Drupal\menu_active_trail_fix\Menu;

use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Decorator for menu.active_trail that is more tolerant with Views routes.
 */
final class ActiveTrailDecorator implements MenuActiveTrailInterface {

  private MenuActiveTrailInterface $inner;
  private RouteMatchInterface $routeMatch;
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
   * Tries to resolve an active link for the given menu.
   */
  private function resolveActiveLink(?string $menu_name = NULL): ?MenuLinkInterface {
    // 0) Let core answer first.
    $core = $this->inner->getActiveLink($menu_name);
    if ($core instanceof MenuLinkInterface) {
      return $core;
    }

    $route_name = $this->routeMatch->getRouteName();
    if (!$route_name) {
      return NULL;
    }
    $route_parameters = $this->routeMatch->getRawParameters()->all() ?? [];

    // 1) Exact parameters.
    $candidates = $this->menuLinkManager->getLinksByRoute($route_name, $route_parameters, $menu_name);
    if (!empty($candidates)) {
      return reset($candidates) ?: NULL;
    }

    // 2) Same route, ignore parameters (typical for Views pages).
    $candidates = $this->menuLinkManager->getLinksByRoute($route_name, [], $menu_name);
    if (!empty($candidates)) {
      return reset($candidates) ?: NULL;
    }

    // 3) Last resort: any link on this route, filtered to the given menu.
    if ($menu_name) {
      foreach ($this->menuLinkManager->getLinksByRoute($route_name) as $link) {
        if ($link->getMenuName() === $menu_name && $link->isEnabled()) {
          return $link;
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrailIds($menu_name): array {
    $ids = $this->inner->getActiveTrailIds($menu_name);
    if (!empty($ids)) {
      return $ids;
    }

    if ($link = $this->resolveActiveLink($menu_name)) {
      $trail = array_merge(
        $this->menuLinkManager->getParentIds($link->getPluginId()),
        [$link->getPluginId()]
      );
      return array_combine($trail, $trail);
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveTrail($menu_name): array {
    $trail = $this->inner->getActiveTrail($menu_name);
    if (!empty($trail)) {
      return $trail;
    }

    $ids = $this->getActiveTrailIds($menu_name);
    if (empty($ids)) {
      return $trail;
    }

    return [
      'active' => array_key_last($ids),
      'trail' => $ids,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL): ?MenuLinkInterface {
    return $this->resolveActiveLink($menu_name) ?? $this->inner->getActiveLink($menu_name);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    $this->inner->resetCache();
  }

}
