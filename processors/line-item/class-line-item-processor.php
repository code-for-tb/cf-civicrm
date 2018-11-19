<?php

/**
 * CiviCRM Caldera Forms Line Item Processor Class.
 *
 * @since 0.4.4
 */
class CiviCRM_Caldera_Forms_Line_Item_Processor {

	/**
	 * Plugin reference.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var object $plugin The plugin instance
	 */
	public $plugin;

	/**
	 * Contact link.
	 *
	 * @since 0.4.4
	 * @access protected
	 * @var string $contact_link The contact link
	 */
	protected $contact_link;

	/**
	 * The processor key.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var str $key_name The processor key
	 */
	public $key_name = 'civicrm_line_item';

	/**
	 * Initialises this object.
	 *
	 * @since 0.4.4
	 */
	public function __construct($plugin) {
		$this->plugin = $plugin;
		// register this processor
		add_filter( 'caldera_forms_get_form_processors', [ $this, 'register_processor' ] );

	}

	/**
	 * Adds this processor to Caldera Forms.
	 *
	 * @since 0.4.4
	 *
	 * @uses 'caldera_forms_get_form_processors' filter
	 *
	 * @param array $processors The existing processors
	 * @return array $processors The modified processors
	 */
	public function register_processor( $processors ) {

		$processors[$this->key_name] = [
			'name' => __( 'CiviCRM Line Item', 'caldera-forms-civicrm' ),
			'description' => __( 'Add Line Item for the Order Processor', 'caldera-forms-civicrm' ),
			'author' => 'Andrei Mondoc',
			'template' => CF_CIVICRM_INTEGRATION_PATH . 'processors/line-item/line_item_config.php',
			'pre_processor' => [ $this, 'pre_processor' ],
			'processor' => [ $this, 'processor' ],
			'magic_tags' => [ 'processor_id' ],
		];

		return $processors;

	}

	/**
	 * Form processor callback.
	 *
	 * @since 0.4.4
	 *
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 */
	public function pre_processor( $config, $form, $processid ) {

	}

	public function processor( $config, $form, $processid ) {

		$transient = $this->plugin->transient->get();
		
		$this->contact_link = 'cid_' . $config['contact_link'];

		// price field value params aka 'line_item'
		$price_field_value = isset( $config['is_fixed_price_field'] ) ? 
			$this->plugin->helper->get_price_field_value( $config['fixed_price_field_value'] ) :
			$this->plugin->helper->get_price_field_value( Caldera_Forms::do_magic_tags( $config['price_field_value'] ) );

		unset( 
			$price_field_value['name'],
			$price_field_value['weight'],
			$price_field_value['is_default'],
			$price_field_value['is_active'],
			$price_field_value['visibility_id']
		);

		// add tax amount
		if ( isset( $price_field_value['tax_amount'] ) && $this->plugin->helper->get_tax_settings()['invoicing'] )
			$price_field_value['amount'] += $price_field_value['tax_amount'];

		if ( ! empty( $config['entity_table'] ) && $price_field_value ) {
			if ( $config['entity_table'] == 'civicrm_membership' ) {
				$price_field_value['entity_table'] = $config['entity_table'];
				$this->process_membership( $config, $form, $transient, $price_field_value );
			}

			if ( $config['entity_table'] == 'civicrm_participant' ) {
				$price_field_value['entity_table'] = $config['entity_table'];
				$this->process_participant( $config, $form, $transient, $price_field_value );
			}

			if ( $config['entity_table'] == 'civicrm_contribution' ) {
				$price_field_value['entity_table'] = $config['entity_table'];
				$this->process_contribution( $config, $form, $transient, $price_field_value );
			}
		} elseif ( $price_field_value ) {
			$entity_table = $this->guess_entity_table( $price_field_value );
			$price_field_value['entity_table'] = $entity_table;
			$entity = str_replace( 'civicrm_', '', $entity_table );
			$this->{'process_' . $entity}( $config, $form, $transient, $price_field_value );
		}

		return ['processor_id' => $config['processor_id']];
	}

	public function guess_entity_table( $price_field_value, $entity_table = false ) {

		// FIXME
		// only for memberships or contributions, need to find a way that checks for all tables
		if ( ! isset( $this->price_sets ) ) $this->price_sets = $this->plugin->helper->cached_price_sets();

		if ( ! empty( $entity_table ) ) return $entity_table;

		if ( array_key_exists( 'membership_type_id', $price_field_value ) ) return 'civicrm_membership';
		return 'civicrm_contribution';


		// find entity table from priceset?

		// foreach ( $this->price_sets as $price_set_id => $price_set ) {
		// 	foreach ( $price_set['price_fields'] as $price_field_id => $price_field ) {
		// 		foreach ( $price_field['price_field_values'] as $price_field_value_id => $price_field_val ) {
		// 			if ( $price_field_value_id == $price_field_value['id'] ) {
		// 				$current_price_set = $this->price_sets[$price_set_id];
		// 				unset( $current_price_set['price_fields'] );
		// 				break;
		// 			}
		// 		}
		// 	}
		// }

	}

	/**
	 * Process Membership Line Item.
	 *
	 * @since 0.4.4
	 * 
	 * @param array $config Processor config
	 * @param array $form Form config
	 * @param object $transient Transient object
	 * @param array $price_field_value The price field value
	 */
	public function process_membership( $config, $form, $transient, $price_field_value ) {

		$price_field_value['price_field_value_id'] = $price_field_value['id'];
		$price_field_value['field_title'] = $price_field_value['label'];
		$price_field_value['unit_price'] = $price_field_value['amount'];
		$price_field_value['qty'] = 1;
		$price_field_value['line_total'] = $price_field_value['amount'] * $price_field_value['qty'];
		
		// membership params aka 'params'
		$processor_id = Caldera_Forms::do_magic_tags( $config['entity_params'] );
		if ( isset( $transient->memberships->$processor_id->params ) && ! empty( $config['entity_params'] ) ) {

			$entity_params = $transient->memberships->$processor_id->params;

			$entity_params['source'] = ! empty( $entity_params['source'] ) ? 
				$entity_params['source'] : 
				$form['name'];

		}

		unset(
			$price_field_value['membership_num_terms'],
			$price_field_value['contribution_type_id'],
			$price_field_value['id'],
			$price_field_value['amount'],
			$entity_params['price_field_value'],
			$entity_params['is_price_field_based']
		);
		$line_item = [ 
			'line_item' => [ $price_field_value['price_field_value_id'] => $price_field_value ],
			'params' => $entity_params
		];

		$transient->line_items->{$config['processor_id']}->params = $line_item;

		$this->plugin->transient->save( $transient->ID, $transient );

	}

	/**
	 * Process Participant Line Item.
	 * 
	 * @since 1.0
	 * 
	 * @param array $config Processor config
	 * @param array $form Form config
	 * @param object $transient Transient object
	 * @param array $price_field_value The price field value
	 */
	public function process_participant( $config, $form, $transient, $price_field_value ) {

		// if price field is disabled by cfc we won't have a price_field_value
		if ( ! $price_field_value['id'] ) return;

		// get price field
		$price_field = $this->plugin->helper->get_price_set_column_by_id( $price_field_value['price_field_id'], 'price_field' );

		$price_field_value['price_field_value_id'] = $price_field_value['id'];
		$price_field_value['label'] = $price_field_value['label'];
		$price_field_value['field_title'] = $price_field['label'];
		$price_field_value['unit_price'] = $price_field_value['amount'];
		$price_field_value['qty'] = 1;
		$price_field_value['line_total'] = $price_field_value['amount'] * $price_field_value['qty'];

		// participant params aka 'params'
		$processor_id = Caldera_Forms::do_magic_tags( $config['entity_params'] );

		if ( isset( $transient->participants->$processor_id->params ) && ! empty( $config['entity_params'] ) ) {

			$entity_params = $transient->participants->$processor_id->params;

			$entity_params['source'] = ! empty( $entity_params['source'] ) ? 
				$entity_params['source'] : 
				$form['name'];

			// need to set price set id, otherwise Participant.create from Order.create
			// will create a non-linked LineItem as the contribution has been created yet
			$entity_params['price_set_id'] = $price_field['price_set_id'];
			$entity_params['fee_level'] = $price_field_value['label'];
			$entity_params['fee_amount'] = $price_field_value['amount'];

		}

		unset(
			$price_field_value['id'],
			$price_field_value['amount'],
			$price_field_value['contribution_type_id'],
			$price_field_value['non_deductible_amount'],
			$entity_params['price_field_value'],
			$entity_params['is_price_field_based']			
		);

		$line_item = [ 
			'line_item' => [ $price_field_value['price_field_value_id'] => $price_field_value ],
			'params' => $entity_params
		];

		$transient->line_items->{$config['processor_id']}->params = $line_item;

		$this->plugin->transient->save( $transient->ID, $transient );

	}

	/**
	 * Process Contribution Line Item.
	 * 
	 * @since 0.4.4
	 * 
	 * @param array $config Processor config
	 * @param array $form Form config
	 * @param object $transient Transient object
	 * @param array $price_field_value The price field value
	 */
	public function process_contribution( $config, $form, $transient, $price_field_value ) {
		
		if ( ! isset( $price_field_value['price_field_value_id'] ) )
			$price_field_value['price_field_value_id'] = $price_field_value['id'];
		
		if( ! isset( $price_field_value['unit_price'], $price_field_value['line_total'] ) )
			$price_field_value['unit_price'] = $price_field_value['line_total'] = $price_field_value['amount'];
		
		if ( ! isset( $price_field_value['field_title'] ) )
			$price_field_value['field_title'] = $price_field_value['label'];
		// assume 1 unit as there's currently no way to change/map this in the processor
		if ( ! isset( $price_field_value['qty'] ) )
			$price_field_value['qty'] = 1;

		if ( isset( $config['is_other_amount'] ) ) {
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values );
			$price_field_value['line_total'] = $price_field_value['unit_price'] = $price_field_value['amount'] = $form_values['amount'];
		}
		
		unset( $price_field_value['contribution_type_id'], $price_field_value['id'] );
		
		$line_item = [
			'line_item' => [ $price_field_value ]
		];

		$transient->line_items->{$config['processor_id']}->params = $line_item;

		$this->plugin->transient->save( $transient->ID, $transient );
	}

}
