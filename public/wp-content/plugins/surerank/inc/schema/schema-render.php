<?php
/**
 * Schema_Render
 *
 * This file will handle functionality for rendering schema data with placeholders and merging it with a specific schema type.
 *
 * @package surerank
 * @since 1.0.0
 */

namespace SureRank\Inc\Schema;

/**
 * Class Schema_Render
 *
 * Responsible for rendering schema data with placeholders and merging it with a specific schema type.
 */
class Schema_Render {
	/**
	 * The schema type (e.g., Article, Product, etc.).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * The fields or data associated with the schema.
	 *
	 * @var array<string, mixed>
	 */
	private $fields;

	/**
	 * The variable renderer for processing dynamic placeholders.
	 *
	 * @var Render
	 */
	private $variable_renderer;

	/**
	 * The schema registry key used to resolve field definitions.
	 *
	 * @var string
	 */
	private $registry_key;

	/**
	 * Schema_Render constructor.
	 *
	 * @param string               $type              The schema type.
	 * @param array<string, mixed> $fields            The fields to render.
	 * @param Render               $variable_renderer The renderer for processing placeholders.
	 * @param string               $registry_key      The key the schema is registered under (e.g. LocalBusiness). May differ from $type when a sub-type is selected.
	 */
	public function __construct( string $type, array $fields, Render $variable_renderer, string $registry_key = '' ) {
		$this->type              = $type;
		$this->fields            = $fields;
		$this->variable_renderer = $variable_renderer;
		$this->registry_key      = $registry_key;
	}

	/**
	 * Render the schema data with resolved placeholders.
	 *
	 * @return array<string, mixed> The rendered schema data merged with the schema type.
	 */
	public function render() {
		foreach ( $this->fields as &$value ) {
			$this->variable_renderer->render( $value );
		}

		if ( isset( $this->fields['sameAs'] ) && is_array( $this->fields['sameAs'] ) ) {
			$this->fields['sameAs'] = array_values( $this->fields['sameAs'] );
		}

		$this->normalize_datetime_fields();
		$this->flatten_cloneable_fields();

		$final_type = $this->type;
		/**
		 * Combine @type and @sub_type if @sub_type is set.
		 */
		if ( isset( $this->fields['@sub_type'] ) && is_array( $this->fields['@sub_type'] ) && ! empty( $this->fields['@sub_type'] ) ) {
			$sub_types = array_filter( $this->fields['@sub_type'] );
			if ( ! empty( $sub_types ) ) {
				$final_type = array_merge( [ $this->type ], $sub_types );
			}
			unset( $this->fields['@sub_type'] );
		}

		$this->fields['@type'] = $final_type;
		$schema                = array_merge( [ '@type' => $final_type ], $this->fields );

		return $this->remove_empty( $this->remove_schema_name( $schema ) );
	}

	/**
	 * Normalize DateTime fields to ISO 8601 output before rendering.
	 *
	 * @return void
	 */
	private function normalize_datetime_fields() {
		$field_definitions = $this->get_schema_field_definitions();

		if ( empty( $field_definitions ) ) {
			return;
		}

		$this->fields = $this->normalize_fields_by_definition( $this->fields, $field_definitions );
	}

	/**
	 * Get field definitions for the current schema type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_schema_field_definitions() {
		$schema_types = Utils::get_schema_types();
		$schema_class = $schema_types[ $this->registry_key ] ?? $schema_types[ $this->type ] ?? null;

		if ( ! $schema_class || ! class_exists( $schema_class ) ) {
			return [];
		}

		return $schema_class::get_instance()->get();
	}

	/**
	 * Normalize fields using their schema definitions.
	 *
	 * @param array<string, mixed>             $values            Current schema field values.
	 * @param array<int, array<string, mixed>> $field_definitions Schema field definitions.
	 * @return array<string, mixed>
	 */
	private function normalize_fields_by_definition( array $values, array $field_definitions ) {
		foreach ( $field_definitions as $field ) {
			$field_id = $field['id'] ?? '';

			if ( '' === $field_id || ! array_key_exists( $field_id, $values ) ) {
				continue;
			}

			$field_type = $field['type'] ?? 'Text';

			if ( 'DateTime' === $field_type ) {
				if ( is_array( $values[ $field_id ] ) ) {
					foreach ( $values[ $field_id ] as $uuid => $date_value ) {
						$values[ $field_id ][ $uuid ] = $this->normalize_datetime_value( $date_value );
					}
				} else {
					$values[ $field_id ] = $this->normalize_datetime_value( $values[ $field_id ] );
				}
				continue;
			}

			if ( 'Group' !== $field_type || empty( $field['fields'] ) || ! is_array( $values[ $field_id ] ) ) {
				continue;
			}

			if ( ! empty( $field['cloneable'] ) ) {
				foreach ( $values[ $field_id ] as $index => $group_item ) {
					if ( is_array( $group_item ) ) {
						$values[ $field_id ][ $index ] = $this->normalize_fields_by_definition( $group_item, $field['fields'] );
					}
				}
				continue;
			}

			$values[ $field_id ] = $this->normalize_fields_by_definition( $values[ $field_id ], $field['fields'] );
		}

		return $values;
	}

	/**
	 * Normalize a date-time string to ISO 8601 when possible.
	 *
	 * @param mixed $value Date-time value to normalize.
	 * @return mixed
	 */
	private function normalize_datetime_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = trim( $value );

		if ( '' === $value || str_contains( $value, '%' ) ) {
			return $value;
		}

		try {
			return ( new \DateTimeImmutable( $value, wp_timezone() ) )->format( DATE_ATOM );
		} catch ( \Exception $exception ) {
			return $value;
		}
	}

	/**
	 * Remove empty or null values from an array recursively.
	 *
	 * @param array<string, mixed> $data The array to filter.
	 * @return array<string, mixed> Filtered array with non-empty values.
	 */
	private function remove_empty( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->remove_empty( $value );
				if ( empty( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
			} elseif ( empty( $value ) && 0 !== $value ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Flatten cloneable fields that have flatten=true.
	 *
	 * Converts UUID-based objects to simple arrays for fields marked with flatten=true.
	 *
	 * @return void
	 */
	private function flatten_cloneable_fields() {
		$field_definitions = $this->get_schema_field_definitions();

		if ( empty( $field_definitions ) ) {
			return;
		}

		foreach ( $field_definitions as $field ) {
			if ( ! empty( $field['cloneable'] ) && ! empty( $field['flatten'] ) && isset( $field['id'] ) ) {
				$field_id = $field['id'];

				if ( isset( $this->fields[ $field_id ] ) && is_array( $this->fields[ $field_id ] ) ) {
					$this->fields[ $field_id ] = array_values( $this->fields[ $field_id ] );
				}
			}
		}
	}

	/**
	 * Remove the schema name and metadata fields from the array.
	 *
	 * @param array<string, mixed> $schema The schema to remove the name from.
	 * @return array<string, mixed> The schema with the name and metadata removed.
	 */
	private function remove_schema_name( array $schema ) {
		if ( isset( $schema['schema_name'] ) ) {
			unset( $schema['schema_name'] );
		}

		// Remove custom fields metadata (used for tracking custom fields in admin).
		if ( isset( $schema['_customFields'] ) ) {
			unset( $schema['_customFields'] );
		}

		return $schema;
	}
}
