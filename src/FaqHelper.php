<?php

namespace Drupal\faq;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Vocabulary;
use \Drupal\Core\Link;

/**
 * Contains static helper functions for FAQ module.
 */
class FaqHelper {

  /**
   * Function to set up the FAQ breadcrumbs for a given taxonomy term.
   *
   * @param null $term
   *
   * @return array
   */
  public static function setFaqBreadcrumb($term = NULL, $node = NULL) {
    $faq_settings = \Drupal::config('faq.settings');
    $site_settings = \Drupal::config('system.site');

    $breadcrumb = array();
    if ($faq_settings->get('custom_breadcrumbs')) {
      if (\Drupal::moduleHandler()->moduleExists('taxonomy') && $term) {
        if(!$node) {
          $breadcrumb[] = [
            'text' => t($term->getName()),
          ];
        } else {
           $breadcrumb[] = [
            'text' => t($node->getTitle()),
          ];
          $breadcrumb[] = Link::fromTextAndUrl(t($term->getName()), URL::fromUserInput('/faq-page/' . $term->id()));
        }
        while ($parents = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadParents($term->id())) {
          $term = array_shift($parents);
          $breadcrumb[] = Link::fromTextAndUrl(t($term->getName()), URL::fromUserInput('/faq-page/' . $term->id()));
        }
      }
      $breadcrumb = array_reverse($breadcrumb);
    }
    return $breadcrumb;
  }

  /**
   * Count number of nodes for a term and its children.
   *
   * @param int $tid
   *   Id of the tadonomy term to count nodes in.
   *
   * @return int
   *   Returns the count of the nodes in the given term.
   */
  public static function taxonomyTermCountNodes($tid) {
    static $count;

    if (!isset($count) || !isset($count[$tid])) {
      $query = \Drupal::database()->select('node', 'n')
        ->fields('n', array('nid'))
        ->addTag('node_access');
      $query->join('taxonomy_index', 'ti', 'n.nid = ti.nid');
      $query->join('node_field_data', 'd', 'd.nid = n.nid');
      $query->condition('n.type', 'faq')
        ->condition('d.status', 1)
        ->condition('ti.tid', $tid);
      $count[$tid] = $query->countQuery()->execute()->fetchField();
    }

    $children_count = 0;
    foreach (FaqHelper::taxonomyTermChildren($tid) as $child_term) {
      $children_count += FaqHelper::taxonomyTermCountNodes($child_term);
    }

    return $count[$tid] + $children_count;
  }

  /**
   * Helper function to taxonomyTermCountNodes() to return list of child terms.
   */
  public static function taxonomyTermChildren($tid) {
    static $children;

    if (!isset($children)) {
      $result = \Drupal::database()->select('taxonomy_term__parent', 'tth')
        ->fields('tth', array('entity_id', 'parent_target_id'))
        ->execute();
      while ($term = $result->fetch()) {
        $children[$term->parent_target_id][] = $term->entity_id;
      }
    }

    return isset($children[$tid]) ? $children[$tid] : array();
  }

  /**
   * Helper function for retrieving the sub-categories faqs.
   *
   * @param $term
   * @param $theme_function
   * @param $default_weight
   * @param $default_sorting
   * @param $category_display
   * @param $class
   * @param null $parent_term
   *
   * @return array
   */
  public static function getChildCategoriesFaqs($term, $theme_function, $default_weight, $default_sorting, $category_display, $class, $parent_term = NULL) {
    $output = array();

    $list = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadChildren($term->id());

    if (!is_array($list)) {
      return '';
    }
    foreach ($list as $tid => $child_term) {
      $child_term->depth = $term->depth + 1;

      if (FaqHelper::taxonomyTermCountNodes($child_term->id())) {
        $query = \Drupal::database()->select('node', 'n');
        $query->join('node_field_data', 'd', 'n.nid = d.nid');
        $ti_alias = $query->innerJoin('taxonomy_index', 'ti', '(n.nid = %alias.nid)');
        $w_alias = $query->leftJoin('faq_weights', 'w', "%alias.tid = {$ti_alias}.tid AND n.nid = %alias.nid");
        $query
          ->fields('n', array('nid'))
          ->condition('n.type', 'faq')
          ->condition('d.status', 1)
          ->condition("{$ti_alias}.tid", $child_term->id())
          ->addTag('node_access');

        $default_weight = 0;
        if ($default_sorting == 'ASC') {
          $default_weight = 1000000;
        }
        // Works, but involves variable concatenation - safe though, since
        // $default_weight is an integer.
        $query->addExpression("COALESCE(w.weight, $default_weight)", 'effective_weight');
        // Doesn't work in Postgres.
        // $query->addExpression('COALESCE(w.weight, CAST(:default_weight as SIGNED))', 'effective_weight', array(':default_weight' => $default_weight));.
        $query->orderBy('effective_weight', 'ASC')
          ->orderBy('d.sticky', 'DESC');
        if ($default_sorting == 'ASC') {
          $query->orderBy('d.created', 'ASC');
        }
        else {
          $query->orderBy('d.created', 'DESC');
        }

        // We only want the first column, which is nid, so that we can load all
        // related nodes.
        $nids = $query->execute()->fetchCol();
        $data = Node::loadMultiple($nids);

        $to_render = array(
          '#theme' => $theme_function,
          '#data' => $data,
          '#display_header' => 1,
          '#category_display' => $category_display,
          '#term' => $child_term,
          '#class' => $class,
          '#parent_term' => $parent_term,
        );
        $output[] = \Drupal::service('renderer')->render($to_render);
      }
    }

    return $output;
  }

  /**
   * Helper function to setup the list of sub-categories for the header.
   *
   * @param $term
   *   The term to setup the list of child terms for.
   *
   * @return
   *   An array of sub-categories.
   */
  public static function viewChildCategoryHeaders($term) {

    $child_categories = array();
    $list = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadChildren($term->id());

    foreach ($list as $tid => $child_term) {
      $term_node_count = FaqHelper::taxonomyTermCountNodes($child_term->id());
      if ($term_node_count) {

        // Get taxonomy image.
        $term_image = '';
        // taxonomy_image does not exists in D8 yet
        // if (module_exists('taxonomy_image')) {
        //  $term_image = taxonomy_image_display($child_term->tid, array('class' => 'faq-tax-image'));
        // }.
        $child_term_id = $child_term->id();
        $term_vars['link'] = Link::fromTextAndUrl(t($child_term->getName()), Url::fromUserInput('/faq-page/' . $child_term_id));
        $term_vars['description'] = ($child_term->getDescription()) ? t($child_term->getDescription()) : '';
        $term_vars['count'] = $term_node_count;
        $term_vars['term_image'] = $term_image;
        $child_categories[] = $term_vars;
      }
    }

    return $child_categories;
  }

  /**
   * Returns an array containing the vocabularies related to the FAQ node type.
   *
   * @return array Array containing the FAQ related vocabularies.
   */
  public static function faqRelatedVocabularies() {
    $vids = array();
    foreach (\Drupal::entityTypeManager()->getStorage('node')->getFieldDefinitions('node', 'faq') as $field_definition) {
      if ($field_definition->getType() == 'taxonomy_term_reference') {
        foreach ($field_definition->getSetting('allowed_values') as $allowed_values) {
          $vids[] = $allowed_values['vocabulary'];
        }
      }
    }

    return Vocabulary::loadMultiple($vids);
  }

  /**
   * Replacement for the old arg() function which is removed in drupal 8.0.0-alpha13
   * TODO: this should be replaced with the a path service when these are not changing any more.
   *
   * @param int $id
   *   Number of the path's part.
   *
   * @return string
   *   The part of the path which indexed by the given id.
   */
  public static function arg($id) {
    $url_comp = explode('/', \Drupal::request()->getRequestUri());
    if (isset($url_comp[$id])) {
      return $url_comp[$id];
    }
    else {
      return NULL;
    }
  }

  /**
   * Helper function to search a string in the path.
   *
   * @param int $id
   *   Number of the path's part.
   *
   * @return string
   *   The id of the path which indexed by the given path.
   */
  public static function searchInArgs($path) {
    $url_comp = explode('/', \Drupal::request()->getRequestUri());
    if ($key = array_search($path, $url_comp)) {
      return $key;
    }
    else {
      return NULL;
    }
  }

}
