<?php
/**
 * Caper class file
 *
 * (c) Alley <info@alley.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package wp-caper
 */

namespace Alley\WP;

/**
 * Fluently distribute capabilities to roles.
 */
final class Caper {
	/**
	 * The roles to which this instance grants or denies capabilities.
	 *
	 * @var string[]
	 */
	private array $positive_roles = [];

	/**
	 * Whether capabilities are being granted or denied.
	 *
	 * @var bool
	 */
	private bool $allow;

	/**
	 * Primitive capabilities to distribute directly.
	 *
	 * @var string[]
	 */
	private array $primitives = [];

	/**
	 * Post types whose capabilities should be distributed.
	 *
	 * @var string[]
	 */
	private array $post_types = [];

	/**
	 * Taxonomies whose capabilities should be distributed.
	 *
	 * @var string[]
	 */
	private array $taxonomies = [];

	/**
	 * For post types or taxonomies, generic primitive capabilities
	 * to grant instead of deny, or vice versa.
	 *
	 * @var string[]
	 */
	private array $exceptions = [];

	/**
	 * For post types or taxonomies, the exclusive set of generic primitive
	 * capabilities to grant or deny.
	 *
	 * @var string[]
	 */
	private array $only = [];

	/**
	 * Priority at which user capabilities are filtered.
	 *
	 * @var int
	 */
	private int $priority;

	/**
	 * A special array used by this class to stand for "all roles."
	 *
	 * @var string[]
	 */
	private const ALL_ROLES = [ '__ALL__' ];

	/**
	 * Meta capabilities for taxonomy terms.
	 *
	 * @var string[]
	 */
	private const TAXONOMY_META_CAPABILITIES = [ 'edit_term', 'delete_term', 'assign_term' ];

	/**
	 * Set up.
	 *
	 * @param string[] $positive_roles The roles to which this instance grants or denies capabilities.
	 * @param bool     $allow          Whether capabilities are being granted or denied.
	 * @param int      $priority       Priority at which to filter user capabilities.
	 */
	private function __construct( array $positive_roles, bool $allow, int $priority ) {
		$this->positive_roles = $positive_roles;
		$this->allow          = $allow;

		$this->add_filter( $priority );
	}

	/**
	 * Start a Caper that grants capabilities to roles.
	 *
	 * @param string|string[] $positive_roles The roles to affect.
	 * @return self Class instance.
	 */
	public static function grant_to( $positive_roles ): self {
		return new self( (array) $positive_roles, true, 10 );
	}

	/**
	 * Start a Caper that grants capabilities to all roles.
	 *
	 * @return self Class instance.
	 */
	public static function grant_to_all(): self {
		return new self( self::ALL_ROLES, true, 10 );
	}

	/**
	 * Start a Caper that denies capabilities to roles.
	 *
	 * @param string|string[] $positive_roles The roles to affect.
	 * @return self Class instance.
	 */
	public static function deny_to( $positive_roles ): self {
		return new self( (array) $positive_roles, false, 10 );
	}

	/**
	 * Start a Caper that denies capabilities to all roles.
	 *
	 * @return static Class instance.
	 */
	public static function deny_to_all(): self {
		return new self( self::ALL_ROLES, false, 10 );
	}

	/**
	 * Set primitive capabilities to grant or deny.
	 *
	 * @param string|string[] $primitives Array of primitive capabilities.
	 * @return self Class instance.
	 */
	public function primitives( $primitives ): self {
		$this->primitives = array_merge( $this->primitives, (array) $primitives );
		$this->primitives = array_unique( $this->primitives );
		return $this;
	}

	/**
	 * Set the post type or taxonomy whose capabilities will be granted or denied.
	 *
	 * A post type and a taxonomy will almost never share a name, making it
	 * redundant to specify "for post type" or "for taxonomy" and cheap to
	 * determine which object type the name corresponds to. Should a post type
	 * and taxonomy share a name, use the Caper::caps_for_post_type() or
	 * Caper::caps_for_taxonomy() methods directly to disambiguate.
	 *
	 * @param string|string[] $type Post type or taxonomy names.
	 * @return self Class instance.
	 */
	public function caps_for( $type ): self {
		return $this
			->caps_for_post_type( $type )
			->caps_for_taxonomy( $type );
	}

	/**
	 * Set the post types whose capabilities will be granted or denied.
	 *
	 * @param string|string[] $type Post type or types.
	 * @return self Class instance.
	 */
	public function caps_for_post_type( $type ): self {
		$this->post_types = array_merge( $this->post_types, (array) $type );
		$this->post_types = array_unique( $this->post_types );
		return $this;
	}

	/**
	 * Set the taxonomies whose capabilities will be granted or denied.
	 *
	 * @param string|string[] $type Taxonomy or taxonomies.
	 * @return self Class instance.
	 */
	public function caps_for_taxonomy( $type ): self {
		$this->taxonomies = array_merge( $this->taxonomies, (array) $type );
		$this->taxonomies = array_unique( $this->taxonomies );
		return $this;
	}

	/**
	 * Set exceptions to the granted or denied post type or taxonomy capabilities.
	 *
	 * The $primitives parameter refers to the "generic" keys in the $cap object
	 * of a \WP_Post_Type or \WP_Taxonomy that correspond to the actual
	 * capability names.
	 *
	 * For example, given a post type with a 'capability_type' of 'book', pass
	 * this method 'edit_published_posts', not 'edit_published_books'. The
	 * actual capabilities to grant or deny will be determined automatically.
	 *
	 * @param string|string[] $primitives Generic capability names to grant instead
	 *                                 of deny, or vice versa, depending on the
	 *                                 value of $allow.
	 * @return self Class instance.
	 */
	public function except( $primitives ): self {
		$this->exceptions = (array) $primitives;
		return $this;
	}

	/**
	 * Set the post type or taxonomy capabilities to exclusively grant or deny.
	 *
	 * The $primitives parameter refers to the "generic" keys in the $cap object
	 * of a \WP_Post_Type or \WP_Taxonomy that correspond to the actual
	 * capability names.
	 *
	 * For example, given a post type with a 'capability_type' of 'book', pass
	 * this method 'edit_published_posts', not 'edit_published_books'. The
	 * actual capabilities to grant or deny will be determined automatically.
	 *
	 * @param string|string[] $primitives Generic capability names to grant or deny.
	 * @return self Class instance.
	 */
	public function only( $primitives ): self {
		$this->only = (array) $primitives;
		return $this;
	}

	/**
	 * Change the priority at which user capabilities are filtered.
	 *
	 * @param int $priority New priority.
	 * @return self Class instance.
	 */
	public function at_priority( int $priority ): self {
		remove_filter( 'user_has_cap', [ $this, 'filter_user_has_cap' ], $this->priority );
		$this->add_filter( $priority );
		return $this;
	}

	/**
	 * Convenience method for chaining multiple related Caper instances.
	 *
	 * For example:
	 *
	 *     Caper::deny_to_all()
	 *         ->caps_for( 'post' )
	 *         ->then_grant_to( 'editor' )
	 *         ->except( 'delete_posts' )
	 *         ->then_grant_to( 'administrator' );
	 *
	 * @param string|string[] $positive_roles The roles to affect.
	 * @return self New class instance.
	 */
	public function then_grant_to( $positive_roles ): self {
		$next = self::grant_to( $positive_roles );
		$this->then_to( $next );
		return $next;
	}

	/**
	 * Convenience method for chaining multiple related Caper instances.
	 *
	 * For example:
	 *
	 *     Caper::grant_to_all()
	 *         ->caps_for( 'post' )
	 *         ->then_deny_to( [ 'subscriber', 'contributor' ] );
	 *
	 * @param string|string[] $positive_roles The roles to affect.
	 * @return self New class instance.
	 */
	public function then_deny_to( $positive_roles ): self {
		$next = self::deny_to( $positive_roles );
		$this->then_to( $next );
		return $next;
	}

	/**
	 * Dynamically filter a user's capabilities.
	 *
	 * @param bool[]   $allcaps An array of all the user's capabilities.
	 * @param string[] $caps    Actual capabilities for meta capability.
	 * @param mixed[]  $args    Optional parameters passed to has_cap(), typically object ID.
	 * @param \WP_User $user    The user object.
	 * @return bool[] The updated array of the user's capabilities.
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );

		if (
			( self::ALL_ROLES === $this->positive_roles && \count( $user->roles ) > 0 )
			|| self::users_roles_intersect( $user, $this->positive_roles )
		) {
			$allcaps = array_merge( $allcaps, $this->get_map() );
		}

		return $allcaps;
	}

	/**
	 * Whether a particular user has a specific role or roles.
	 *
	 * @param int|\WP_User    $user  User ID or object.
	 * @param string|string[] $roles Role name or names to check.
	 * @return bool Whether the user has any of the given roles.
	 */
	public static function users_roles_intersect( $user, $roles ): bool {
		if ( ! ( $user instanceof \WP_User ) ) {
			$user = get_userdata( $user );
		}

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( ! \is_array( $roles ) ) {
			$roles = [ $roles ];
		}

		$comparison = array_intersect( $roles, (array) $user->roles );

		return ( \count( $comparison ) > 0 );
	}

	/**
	 * Add instance to 'user_has_cap' at the given priority.
	 *
	 * @param int $priority New priority.
	 */
	private function add_filter( int $priority ): void {
		$this->priority = $priority;

		add_filter( 'user_has_cap', [ $this, 'filter_user_has_cap' ], $this->priority, 4 );
	}

	/**
	 * Get the associative array of capabilities to be granted or denied.
	 *
	 * @return bool[] Array of capabilities and their status.
	 */
	private function get_map() {
		$result = [];

		if ( $this->primitives ) {
			$result = array_merge( $result, array_fill_keys( $this->primitives, $this->allow ) );
		}

		foreach ( $this->post_types as $post_type ) {
			$pt_object = get_post_type_object( $post_type );

			if ( $pt_object instanceof \WP_Post_Type ) {
				$post_type_primitives_map = $this->get_post_type_primitives_map( $pt_object );

				$result = array_merge( $result, $this->map_primitives_array( $post_type_primitives_map ) );
			}
		}

		foreach ( $this->taxonomies as $taxonomy ) {
			$tax_object = get_taxonomy( $taxonomy );

			if ( $tax_object instanceof \WP_Taxonomy ) {
				$taxonomy_primitives_map = $this->get_taxonomy_primitives_map( $tax_object );

				$result = array_merge( $result, $this->map_primitives_array( $taxonomy_primitives_map ) );
			}
		}

		return $result;
	}

	/**
	 * Get the map of generic to actual primitive capabilities for a post type.
	 *
	 * @param \WP_Post_Type $post_type Post type object.
	 * @return string[] The map of capabilities cast as an array.
	 */
	private function get_post_type_primitives_map( \WP_Post_Type $post_type ) {
		global $post_type_meta_caps;

		/*
		 * Pretty close to guaranteed that each known meta cap will be in the array
		 * values as long as one post type is registered with 'map_meta_cap'.
		 */
		$meta_caps = array_unique( array_values( $post_type_meta_caps ?? [] ) );

		$cap = get_object_vars( $post_type->cap );

		foreach ( array_keys( $cap ) as $core_cap ) {
			if ( \in_array( $core_cap, $meta_caps, true ) ) {
				unset( $cap[ $core_cap ] );
			}
		}

		/*
		 * If the $read cap is set to 'read', which it is for all post types by
		 * default, then don't disrupt it. But if $read was configured in the
		 * 'capabilities' argument to `register_post_type()`, include it to be
		 * granted or denied.
		 */
		if ( isset( $cap['read'] ) && 'read' === $cap['read'] ) {
			unset( $cap['read'] );
		}

		$cap = array_filter( $cap, 'is_string' );

		return $cap;
	}

	/**
	 * Get the primitive capability keys and names for a taxonomy.
	 *
	 * @param \WP_Taxonomy $taxonomy Taxonomy to use.
	 * @return string[] Associative array of primitive taxonomy capability keys and their values for the taxonomy.
	 */
	private function get_taxonomy_primitives_map( \WP_Taxonomy $taxonomy ) {
		$cap = get_object_vars( (object) $taxonomy->cap );

		foreach ( array_keys( $cap ) as $core_cap ) {
			if ( \in_array( $core_cap, self::TAXONOMY_META_CAPABILITIES, true ) ) {
				unset( $cap[ $core_cap ] );
			}
		}

		$cap = array_filter( $cap, 'is_string' );

		return $cap;
	}

	/**
	 * Get the associative array of an object's capabilities to grant or deny.
	 *
	 * @param string[] $map Map of post type or taxonomy capabilities.
	 * @return bool[] Array of capabilities and their status.
	 */
	private function map_primitives_array( array $map ) {
		$result = array_fill_keys( array_values( $map ), $this->allow );

		if ( $this->only ) {
			foreach ( array_keys( $map ) as $primitive ) {
				if ( \in_array( $primitive, $this->only, true ) ) {
					continue;
				}

				$result[ $map[ $primitive ] ] = ! $this->allow;
			}
		}

		foreach ( $this->exceptions as $exception ) {
			if ( isset( $map[ $exception ] ) ) {
				$result[ $map[ $exception ] ] = ! $this->allow;
			}
		}

		return $result;
	}

	/**
	 * Copy this instance's settings into an instance that runs after this one.
	 *
	 * @param self $instance Other class instance.
	 */
	private function then_to( self $instance ): void {
		if ( $this->primitives ) {
			$instance->primitives( $this->primitives );
		}

		if ( $this->post_types ) {
			$instance->caps_for_post_type( $this->post_types );
		}

		if ( $this->taxonomies ) {
			$instance->caps_for_taxonomy( $this->taxonomies );
		}

		$instance->at_priority( $this->priority + 1 );
	}
}
