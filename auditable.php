<?php

/**
 * Records changes made to an object during save operations.
 */
class AuditableBehavior extends ModelBehavior {
	/**
	 * A copy of the object as it existed prior to the save. We're going
	 * to store this off so we can calculate the deltas after save.
	 *
	 * @var		Object
	 * @access	private
	 */
	private $_original = null;

	/**
	 * Initiate behavior for the model using specified settings.
	 *
	 * Available settings:
	 * 	 - ignore	array, optional
	 * 	          An array of property names to be ignored when records
	 * 						are created in the deltas table.
	 * 	 - habtm	array, optional
	 * 	          An array of models that have a HABTM relationship with
	 * 	          the acting model and whose changes should be monitored
	 * 	          with the model.
   *
	 * @param 	object 	$model 		  Model using the behavior
	 * @param 	array 	$settings 	Settings overrides.
	 */
	public function setup( $model, $settings = array() ) {
		if( !isset( $this->settings[$model->alias] ) ) {
			$this->settings[$model->alias] = array(
				'ignore' => array( 'created', 'updated', 'modified' ),
				'habtm'  => count( $model->hasAndBelongsToMany ) > 0
					? array_keys( $model->hasAndBelongsToMany )
					: array()
			);
		}
		if( !is_array( $settings ) ) {
			$settings = array();
		}
		$this->settings[$model->alias] = array_merge( $this->settings[$model->alias], $settings );

		/**
		 * Ensure that no HABTM models which are already auditable
		 * snuck into the settings array. That would be bad. Same for
		 * any model which isn't a HABTM association.
		 */
		foreach( $this->settings[$model->alias]['habtm'] as $index => $model_name ) {
			/**
			 * Note the "===" in the condition. The type check is important,
			 * so don't change it just because it may look like a mistake.
			 */
			if( !array_key_exists( $model_name, $model->hasAndBelongsToMany ) || array_search( 'Auditable', $model->$model_name->actsAs ) === true ) {
				unset( $this->settings[$model->alias]['habtm'][$index] );
			}
		}
	}

	/**
	 * function beforeSave
	 * Executed before a save() operation.
	 *
	 * @return	Boolean
	 */
	public function beforeSave( $model ) {
		/**
		 * If we're editing an existing object, save off a copy of
		 * the object as it exists before any changes.
		 */
		if( !empty( $model->id ) ) {
			$this->_original = $this->_getModelData( $model );
		}
	}

	/**
	 * function afterSave
	 * Executed after a save operation completes.
	 *
	 * @param 	$created	Boolean. True if the save operation was an
	 * 						insertion. False otherwise.
	 * @return	void
	 */
	public function afterSave( $model, $created ) {
		$audit = $this->_getModelData( $model );
		$audit[$model->alias][$model->primaryKey] = $model->id;

		/**
		 * Create a runtime association with the Audit model and bind the
		 * Audit model to its AuditDelta model.
		 */
		$model->bindModel(
			array( 'hasMany' => array( 'Audit' ) )
		);
		$model->Audit->bindModel(
			array( 'hasMany' => array( 'AuditDelta' ) )
		);
		
		# TODO: Create callback to retrieve current user
		$sourceId = null;
		
		$data = array(
			'Audit' => array(
				'event'     => $created ? 'CREATE' : 'EDIT',
				'model'     => $model->alias,
				'entity_id' => $model->id,
				'json_object' => json_encode( $audit ),
				'source_id'	=> $sourceId
			)
		);

		/**
		 * We have the audit_logs record, so let's collect the set of
		 * records that we'll insert into the audit_log_deltas table.
		 */
		$updates = array();
		foreach( $audit[$model->alias] as $property => $value ) {
			$delta = array();

			/**
			 * Ignore specified properties
			 */
			if( in_array( $property, $this->settings[$model->alias]['ignore'] )  ) {
				continue;
			}

			if( $created ) {
				/**
				 * For newly created objects, we're going to insert every
				 * non-ignored property with null old_value fields.
				 */
				$delta = array(
					'AuditDelta' => array(
						'audit_log_id'  => $model->Audit->id,
						'property_name' => $property,
						'old_value'     => null,
						'new_value'     => $value
					)
				);
				array_push( $updates, $delta );
			}
			else {
				if( array_key_exists( $property, $this->_original[$model->alias] ) && $this->_original[$model->alias][$property] != $value ) {
					/**
					 * If the property exists in the original _and_ the
					 * value is different, store it.
					 */
					$delta = array(
						'AuditDelta' => array(
							'property_name' => $property,
							'old_value'     => $this->_original[$model->alias][$property],
							'new_value'     => $value
						)
					);
					array_push( $updates, $delta );
				}
			}
		}

		if( count( $updates ) ) {
			$model->Audit->create();
			$model->Audit->save( $data );

			if( $created ) {
				if( method_exists( $model, 'afterAuditCreate' ) ) {
					$model->afterAuditCreate( $model );
				}
			}
			else {
				if( method_exists( $model, 'afterAuditUpdate' ) ) {
					$model->afterAuditUpdate( $model, $this->_original, $updates, $model->Audit->id );
				}
			}

			foreach( $updates as $delta ) {
				$delta['AuditDelta']['audit_id'] = $model->Audit->id;

				$model->Audit->AuditDelta->create();
				$model->Audit->AuditDelta->save( $delta );

				if( !$created && method_exists( $model, 'afterAuditProperty') ) {
					$model->afterAuditProperty(
						$model,
						$delta['AuditDelta']['property_name'],
						$this->_original[$model->alias][$delta['AuditDelta']['property_name']],
						$delta['AuditDelta']['new_value']
					);
				}
			}
		}

		/**
		 * Destroy the runtime association with the Audit
		 */
		$model->unbindModel(
			array( 'hasMany' => array( 'Audit' ) )
		);

		/**
		 * Unset the original object value so it's ready for the next
		 * call.
		 */
		if( isset( $this->_original ) ) {
			$this->_original = null;
		}
	}

	/**
	 * function _getModelData
	 * Retrieves the entire set model data contained to the primary
	 * object and any/all HABTM associated data that has been configured
	 * with the behavior.
	 *
	 * Additionally, for the HABTM data, all we care about is the IDs,
	 * so the data will be reduced to an indexed array of those IDs.
	 *
	 * @param 	$model
	 * @return	array
	 */
	private function _getModelData( $model ) {
		/**
		 * Retrieve the model data along with its appropriate HABTM
		 * model data.
		 */
		$data = $model->find(
			'first',
			array(
				'contain' => !empty( $this->settings[$model->alias]['habtm']  )
					? array_values( $this->settings[$model->alias]['habtm'] )
					: array(),
				'conditions' => array( $model->alias . '.' . $model->primaryKey => $model->id )
			)
		);

		$audit_data = array(
			$model->alias => $data[$model->alias]
		);

		foreach( $this->settings[$model->alias]['habtm'] as $habtm_model ) {
			if( array_key_exists( $habtm_model, $model->hasAndBelongsToMany ) ) {
				$habtm_ids = Set::combine(
					$data[$habtm_model],
					'{n}.id',
					'{n}.id'
				);
				/**
				 * Grab just the id values and sort those
				 */
				$habtm_ids = array_values( $habtm_ids );
				sort( $habtm_ids );

				$audit_data[$model->alias][$habtm_model] = implode( ',', $habtm_ids );
			}
		}

		return $audit_data;
	}
}
