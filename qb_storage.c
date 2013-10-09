/*
  +----------------------------------------------------------------------+
  | PHP Version 5                                                        |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2012 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Chung Leong <cleong@cal.berkeley.edu>                        |
  +----------------------------------------------------------------------+
*/

/* $Id$ */

#include "qb.h"

static uint32_t qb_set_array_dimensions_from_byte_count(qb_storage *storage, qb_address *address, uint32_t byte_count) {
	uint32_t element_count = ELEMENT_COUNT(byte_count, address->type);
	uint32_t item_element_count, item_byte_count, dimension, dimension_expected;
	qb_address *dimension_address, *item_size_address;

	if(address->dimension_count > 1) {
		item_size_address = address->array_size_addresses[1];
		if(CONSTANT(item_size_address)) {
			item_element_count = VALUE_IN(storage, U32, item_size_address);
			item_byte_count = BYTE_COUNT(item_element_count, address->type);
		} else {
			// cannot determine the dimension since the lower dimensions aren't defined 
			qb_abort("Array has undefined dimensions");
		}
		dimension = byte_count / item_byte_count;
	} else {
		dimension = element_count;
		item_byte_count = BYTE_COUNT(1, address->type);
	}

	// make sure the number of bytes is a multiple of the entry size
	if(byte_count != dimension * item_byte_count) {
		qb_abort("Number of bytes in string (%d) is not divisible by the size of each array entry (%d)", byte_count, item_byte_count);
	}

	dimension_address = address->dimension_addresses[0];
	dimension_expected = VALUE_IN(storage, U32, dimension_address);
	if(dimension > dimension_expected) {
		if(CONSTANT(dimension_address)) {
			// dimension is defined
			qb_abort("Number of entries (%d) exceeds the declared size of the array (%d)", dimension, dimension_expected);
		}
		VALUE_IN(storage, U32, dimension_address) = dimension;
		if(address->dimension_count > 1) {
			// set the array size as well (since it's not the same as the dimension)
			VALUE_IN(storage, U32, address->array_size_address) = element_count;
		}
	} else if(dimension < dimension_expected) {
		element_count = VALUE_IN(storage, U32, address->array_size_address);
	}
	return element_count;
}

static uint32_t qb_get_zend_array_size(zval *zvalue) {
	if(Z_TYPE_P(zvalue) == IS_ARRAY) {
		HashTable *ht = Z_ARRVAL_P(zvalue);
		return ht->nNextFreeElement;
	} else {
		if(Z_TYPE_P(zvalue) == IS_NULL) {
			return 0;
		} else {
			return 1;
		}
	}
}

static int32_t qb_is_linear_zval_array(zval *zvalue) {
	HashTable *ht = Z_ARRVAL_P(zvalue);
	Bucket *p;
	for(p = ht->pListHead; p; p = p->pListNext) {
		if(p->nKeyLength == 0 && (long) p->h >= 0) {
			zval **p_element = p->pData;
			if(Z_TYPE_PP(p_element) == IS_ARRAY || Z_TYPE_PP(p_element) == IS_OBJECT) {
				return FALSE;
			}
		}
	}
	return TRUE;
}

#include "qb_storage_file.c"
#include "qb_storage_gd_image.c"

static uint32_t qb_set_array_dimensions_from_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	gdImagePtr image;
	php_stream *stream;

	if(Z_TYPE_P(zvalue) == IS_ARRAY || Z_TYPE_P(zvalue) == IS_NULL || Z_TYPE_P(zvalue) == IS_LONG || Z_TYPE_P(zvalue) == IS_DOUBLE) {
		qb_address *dimension_address = address->dimension_addresses[0];
		uint32_t dimension = qb_get_zend_array_size(zvalue);
		uint32_t dimension_expected = VALUE_IN(storage, U32, dimension_address);

		if(CONSTANT(dimension_address)) {
			if(dimension > dimension_expected) {
				if(MULTIDIMENSIONAL(address) && FIXED_LENGTH(address)) {
					// maybe we're trying to initialize a multidimensional array with a linear array
					if(qb_is_linear_zval_array(zvalue)) {
						uint32_t array_size = Z_ARRVAL_P(zvalue)->nNextFreeElement;
						uint32_t array_size_expected = ARRAY_SIZE_IN(storage, address);
						if(array_size > array_size_expected) {
							qb_abort("Number of elements (%d) exceeds the declared size of the array (%d)", array_size, array_size_expected);
						}
						return array_size_expected;
					}
				}
				// dimension is defined
				qb_abort("Number of elements (%d) exceeds the declared size of the array (%d)", dimension, dimension_expected);
			}
			dimension = dimension_expected;
		} else {
			VALUE_IN(storage, U32, dimension_address) = dimension;
		}
		if(address->dimension_count > 1) {
			qb_address item_address, *item_dimension_address, *item_array_size_address, *array_size_address;
			uint32_t item_array_size, array_size;

			item_address = *address;
			item_address.dimension_addresses++;
			item_address.array_size_addresses++;
			item_address.dimension_count--;
			item_dimension_address = item_address.dimension_addresses[0];
			if(item_address.index_alias_schemes) {
				item_address.index_alias_schemes++;
			}

			// set the dimension to zero first
			if(!CONSTANT(item_dimension_address)) {
				VALUE_IN(storage, U32, item_dimension_address) = 0;
			}

			// set the dimensions of sub-arrays (order doesn't matter)
			if(Z_TYPE_P(zvalue) == IS_ARRAY) {
				HashTable *ht = Z_ARRVAL_P(zvalue);
				Bucket *p;

				for(p = ht->pListHead; p; p = p->pListNext) {
					if(p->nKeyLength == 0 && (long) p->h >= 0) {
						zval **p_element = p->pData;
						qb_set_array_dimensions_from_zval(storage, &item_address, *p_element);
					}
				}
			} else {
				// pass the value along to set the long dimension to either one or zero (if they were not defined)
				qb_set_array_dimensions_from_zval(storage, &item_address, zvalue);
			}

			// calculate the array size (i.e. element count)
			item_array_size_address = item_address.array_size_addresses[0];
			item_array_size = VALUE_IN(storage, U32, item_array_size_address);
			array_size_address = address->array_size_addresses[0];
			array_size = dimension * item_array_size;
			VALUE_IN(storage, U32, array_size_address) = array_size;
			return array_size;
		} else {
			// don't need to calculate the array size here, as array_size_address
			// is pointing to the same value as dimension_address
			return dimension;
		}
	} else if(Z_TYPE_P(zvalue) == IS_OBJECT) {
		if(address->index_alias_schemes && address->index_alias_schemes[0]) {
			return ARRAY_SIZE_IN(storage, address);
		} else {
			qb_abort("Unable to convert an object to an array");
		}
	} else if(Z_TYPE_P(zvalue) == IS_STRING) {
		return qb_set_array_dimensions_from_byte_count(storage, address, Z_STRLEN_P(zvalue));
	} else if((image = qb_get_gd_image(zvalue))) {
		return qb_set_array_dimensions_from_image(storage, address, image);
	} else if((stream = qb_get_file_stream(zvalue))) {
		return qb_set_array_dimensions_from_file(storage, address, stream);
	} else {
		const char *ztype_name = zend_get_type_by_const(Z_TYPE_P(zvalue));
		qb_abort("Cannot convert %s to an array", ztype_name);
		return 0;
	}
}

static void qb_copy_element_from_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	if(Z_TYPE_P(zvalue) == IS_LONG || Z_TYPE_P(zvalue) == IS_BOOL) {
		long value = Z_LVAL_P(zvalue);
#if SIZEOF_LONG == 8
		qb_copy_element(QB_TYPE_S64, (int8_t *) &value, address->type, ARRAY_IN(storage, I08, address));
#else
		qb_copy_element(QB_TYPE_S32, (int8_t *) &value, address->type, ARRAY_IN(storage, I08, address));
#endif
	} else if(Z_TYPE_P(zvalue) == IS_DOUBLE) {
		double value = Z_DVAL_P(zvalue);
		qb_copy_element(QB_TYPE_F64, (int8_t *) &value, address->type, ARRAY_IN(storage, I08, address));
	} else if(Z_TYPE_P(zvalue) == IS_STRING) {
		uint32_t type_size = type_sizes[address->type];
		uint32_t string_len = Z_STRLEN_P(zvalue);
		const char *string = Z_STRVAL_P(zvalue);
		if(type_size != string_len) {
			qb_abort("Cannot convert string to %s due to size mismatch", type_names[address->type]);
		}
		qb_copy_element(address->type, (int8_t *) string, address->type, ARRAY_IN(storage, I08, address));
	} else if(Z_TYPE_P(zvalue) == IS_ARRAY || Z_TYPE_P(zvalue) == IS_CONSTANT_ARRAY) {
		switch(address->type) {
			case QB_TYPE_S64:
			case QB_TYPE_U64: {
				VALUE_IN(storage, I64, address) = qb_zval_array_to_int64(zvalue);
			}	break;
			default:
				qb_abort("Cannot convert an array to %s", type_names[address->type]);
		}
	} else if(Z_TYPE_P(zvalue) == IS_NULL) {
		memset(ARRAY_IN(storage, I08, address), 0, BYTE_COUNT(1, address->type));
	}
}

static void qb_copy_elements_from_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	uint32_t i;
	gdImagePtr image;
	php_stream *stream;

	if(Z_TYPE_P(zvalue) == IS_ARRAY || Z_TYPE_P(zvalue) == IS_OBJECT) {
		qb_address *dimension_address = address->dimension_addresses[0];
		qb_address _item_address, *item_address = &_item_address;
		uint32_t item_element_count, item_byte_count, dimension = VALUE_IN(storage, U32, dimension_address);
		memset(item_address, 0, sizeof(qb_address));

		item_address->dimension_count = address->dimension_count - 1;
		item_address->type = address->type;
		item_address->flags = address->flags;
		item_address->segment_selector = address->segment_selector;
		item_address->segment_offset = address->segment_offset;
		if(item_address->dimension_count > 0) {
			item_address->mode = QB_ADDRESS_MODE_ARR;
			item_address->dimension_addresses = address->dimension_addresses + 1;
			item_address->array_size_addresses = address->array_size_addresses + 1;
			item_address->array_size_address = item_address->array_size_addresses[0];
			if(address->index_alias_schemes) {
				item_address->index_alias_schemes = address->index_alias_schemes + 1;
			}
			item_element_count = ARRAY_SIZE_IN(storage, item_address);
		} else {
			item_address->mode = QB_ADDRESS_MODE_ELE;
			item_element_count = 1;
		}
		item_byte_count = BYTE_COUNT(item_element_count, item_address->type);

		if(Z_TYPE_P(zvalue) == IS_ARRAY) {
			HashTable *ht = Z_ARRVAL_P(zvalue);
			uint32_t array_size = ht->nNextFreeElement;

			if(array_size > dimension) {
				if(item_address->dimension_count > 0 && qb_is_linear_zval_array(zvalue)) {
					// initializing a multidimensional array with a linear array
					item_address->dimension_count = 0;
					item_address->mode = QB_ADDRESS_MODE_ELE;
					item_address->dimension_addresses =
					item_address->array_size_addresses = NULL;
					item_address->array_size_address = NULL;
					item_element_count = 1;
					item_byte_count = BYTE_COUNT(item_element_count, item_address->type);
				}
			}
			for(i = 0; i < array_size; i++) {
				zval **p_item;
				if(zend_hash_index_find(ht, i, (void **) &p_item) == SUCCESS) {
					if(item_address->dimension_count > 0) {
						qb_copy_elements_from_zval(storage, item_address, *p_item);
					} else { 
						qb_copy_element_from_zval(storage, item_address, *p_item);
					}
				} else {
					memset(ARRAY_IN(storage, I08, item_address), 0, item_byte_count);
				}
				item_address->segment_offset += item_byte_count;
			}
			if(array_size < dimension) {
				qb_copy_wrap_around(ARRAY_IN(storage, I08, address), array_size * item_byte_count, dimension * item_byte_count);
			}
		} else {
			qb_index_alias_scheme *scheme = address->index_alias_schemes[0];
			TSRMLS_FETCH();
			for(i = 0; i < dimension; i++) {
				zval **p_item, *item = NULL;
				zval *alias = qb_cstring_to_zval(scheme->aliases[i] TSRMLS_CC);
				p_item = Z_OBJ_GET_PROP_PTR_PTR(zvalue, alias);

				if(p_item) {
					item = *p_item;
				} else if(Z_OBJ_HT_P(zvalue)->read_property) {
					item = Z_OBJ_READ_PROP(zvalue, alias);
				}
				if(item && Z_TYPE_P(item) != IS_NULL) {
					if(item_address->dimension_count > 0) {
						qb_copy_elements_from_zval(storage, item_address, item);
					} else {
						qb_copy_element_from_zval(storage, item_address, item);
					}
				} else {
					memset(ARRAY_IN(storage, I08, item_address), 0, item_byte_count);
				}
				if(!p_item && item) {
					Z_ADDREF_P(item);
					zval_ptr_dtor(&item);
				}
				item_address->segment_offset += item_byte_count;
			}
		}
	} else if(Z_TYPE_P(zvalue) == IS_STRING) {
		// copy the bytes from the string
		int8_t *memory = (int8_t *) Z_STRVAL_P(zvalue);
		uint32_t element_count = VALUE_IN(storage, U32, address->array_size_address);
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);
		memcpy(ARRAY_IN(storage, I08, address), memory, byte_count);
		if((uint32_t) Z_STRLEN_P(zvalue) < byte_count) {
			qb_copy_wrap_around(ARRAY_IN(storage, I08, address), Z_STRLEN_P(zvalue), byte_count);
		}
	} else if(Z_TYPE_P(zvalue) == IS_NULL) {
		// clear the bytes 
		uint32_t element_count = VALUE_IN(storage, U32, address->array_size_address);
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);
		memset(ARRAY_IN(storage, I08, address), 0, byte_count);
	} else if(Z_TYPE_P(zvalue) == IS_LONG || Z_TYPE_P(zvalue) == IS_DOUBLE) {
		uint32_t element_count = VALUE_IN(storage, U32, address->array_size_address);
		// copy the one element
		qb_copy_element_from_zval(storage, address, zvalue);
		if(element_count > 1) {
			uint32_t byte_count = BYTE_COUNT(element_count, address->type);
			qb_copy_wrap_around(ARRAY_IN(storage, I08, address), BYTE_COUNT(1, address->type), byte_count);
		}
	} else if((stream = qb_get_file_stream(zvalue))) {
		// copy the content from the file 
		qb_copy_elements_from_file(storage, address, stream);
	} else if((image = qb_get_gd_image(zvalue))) {
		// copy the content from the image
		qb_copy_elements_from_gd_image(storage, address, image);
	}
}

static void qb_copy_element_to_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	zval_dtor(zvalue);
	switch(address->type) {
		case QB_TYPE_S08: {
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, S08, address);
		}	break;
		case QB_TYPE_U08: {
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, U08, address);
		}	break;
		case QB_TYPE_S16: {
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, S16, address);
		}	break;
		case QB_TYPE_U16: {
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, U16, address);
		}	break;
		case QB_TYPE_S32: {
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, S32, address);
		}	break;
		case QB_TYPE_U32: {
#if SIZEOF_LONG == 8
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, U32, address);
#else
			CTYPE(U32) value = VALUE_IN(storage, U32, address);
			if(value & 0x80000000) {
				// the sign bit is used--save it to a double
				Z_TYPE_P(zvalue) = IS_DOUBLE;
				Z_DVAL_P(zvalue) = (double) value;
			} else {
				// we can safely put it in a 32-bit long
				Z_TYPE_P(zvalue) = IS_LONG;
				Z_LVAL_P(zvalue) = (long) value;
			}
#endif
		}	break;
		case QB_TYPE_S64: {
#if SIZEOF_LONG == 8
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, S64, address);
#else
			CTYPE(S64) value = VALUE_IN(storage, S64, address);
			if(value & 0xFFFFFFFF00000000LL) {
				// the upper bits are used--save it to a double to preserve as much info as we can
				Z_TYPE_P(zvalue) = IS_DOUBLE;
				Z_DVAL_P(zvalue) = (double) value;
			} else {
				// we can safely put it in a 32-bit long
				Z_TYPE_P(zvalue) = IS_LONG;
				Z_LVAL_P(zvalue) = (long) value;
			}
#endif
		}	break;
		case QB_TYPE_U64: {
#if SIZEOF_LONG == 8
			Z_TYPE_P(zvalue) = IS_LONG;
			Z_LVAL_P(zvalue) = VALUE_IN(storage, U64, address);
#else
			CTYPE(U64) value = VALUE_IN(storage, U64, address);
			if(value & 0xFFFFFFFF80000000LL) {
				// the upper bits are used--save it to a double to preserve as much info as we can
				Z_TYPE_P(zvalue) = IS_DOUBLE;
				Z_DVAL_P(zvalue) = (double) value;
			} else {
				// we can safely put it in a 32-bit long
				Z_TYPE_P(zvalue) = IS_LONG;
				Z_LVAL_P(zvalue) = (long) value;
			}
#endif
		}	break;
		case QB_TYPE_F32: {
			Z_TYPE_P(zvalue) = IS_DOUBLE;
			Z_DVAL_P(zvalue) = VALUE_IN(storage, F32, address);
		}	break;
		case QB_TYPE_F64: {
			Z_TYPE_P(zvalue) = IS_DOUBLE;
			Z_DVAL_P(zvalue) = VALUE_IN(storage, F64, address);
		}	break;
		default: break;
	}
}

static void qb_initialize_zval_array(qb_storage *storage, qb_address *item_address, zval *container, zval *item) {	
	zend_class_entry *ce = NULL;
	TSRMLS_FETCH();
	if(item_address->index_alias_schemes && item_address->index_alias_schemes[0]) {
		qb_index_alias_scheme *scheme = item_address->index_alias_schemes[0];
		if(scheme->class_name) {
			if(!scheme->zend_class) {

				zend_class_entry **p_ce;
				if(zend_lookup_class(scheme->class_name, scheme->class_name_length, &p_ce TSRMLS_CC) == FAILURE) {
					qb_abort("Class '%s' not found", scheme->class_name);
				} else if((*p_ce)->ce_flags & (ZEND_ACC_INTERFACE|ZEND_ACC_IMPLICIT_ABSTRACT_CLASS|ZEND_ACC_EXPLICIT_ABSTRACT_CLASS)) {
					qb_abort("Cannot instantiate interface or abstract class '%s'", scheme->class_name);
				}
				scheme->zend_class = *p_ce;
			}
		}
		ce = scheme->zend_class;

	}
	if(!ce && container) {
		// use the class of the first item in the container (if there's one)
		HashTable *ht = Z_ARRVAL_P(container);
		if(ht->pListHead) {
			zval **p_element = ht->pListHead->pData, *element = *p_element;
			if(Z_TYPE_P(element) == IS_OBJECT) {
				ce = Z_OBJCE_P(element);
			}
		}
	}
	if(!ce && item_address->index_alias_schemes && item_address->index_alias_schemes[0]) {
		// use standard class if a scheme is provided but no explicit class name given
		ce = zend_standard_class_def;
	}
	if(ce) {
		object_init_ex(item, ce);
	} else {
		uint32_t size = VALUE_IN(storage, U32, item_address->dimension_addresses[0]);
		array_init_size(item, size);
	}
}

static void qb_copy_elements_to_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	qb_address *dimension_address = address->dimension_addresses[0];
	uint32_t dimension = VALUE_IN(storage, U32, dimension_address);
	uint32_t i;
	gdImagePtr image;
	php_stream *stream;

	if(Z_TYPE_P(zvalue) == IS_ARRAY || Z_TYPE_P(zvalue) == IS_OBJECT) {
		qb_address _item_address, *item_address = &_item_address;
		uint32_t item_element_count, item_byte_count;

		item_address->type = address->type;
		item_address->segment_selector = address->segment_selector;
		item_address->segment_offset = address->segment_offset;
		item_address->dimension_count = address->dimension_count - 1;
		item_address->array_index_address = NULL;
		if(item_address->dimension_count > 0) {
			item_address->mode = QB_ADDRESS_MODE_ARR;
			item_address->dimension_addresses = address->dimension_addresses + 1;
			item_address->array_size_addresses = address->array_size_addresses + 1;
			item_address->array_size_address = item_address->array_size_addresses[0];
			if(address->index_alias_schemes) {
				item_address->index_alias_schemes = address->index_alias_schemes + 1;
			} else {
				item_address->index_alias_schemes = NULL;
			}
			item_element_count = VALUE_IN(storage, U32, item_address->array_size_address);
		} else {
			item_address->mode = QB_ADDRESS_MODE_ELE;
			item_address->dimension_addresses = NULL;
			item_address->array_size_addresses = NULL;
			item_address->array_size_address = NULL;
			item_address->array_index_address = NULL;
			item_address->index_alias_schemes = NULL;
			item_element_count = 1;
		}
		item_byte_count = BYTE_COUNT(item_element_count, address->type);

		if(Z_TYPE_P(zvalue) == IS_ARRAY) {
			HashTable *ht = Z_ARRVAL_P(zvalue);
			for(i = 0; i < dimension; i++) {
				zval **p_item, *item;

				if(zend_hash_index_find(ht, i, (void **) &p_item) == SUCCESS) {
					SEPARATE_ZVAL_TO_MAKE_IS_REF(p_item);
					item = *p_item;
				} else {
					ALLOC_INIT_ZVAL(item);
					if(item_address->dimension_count > 0) {
						qb_initialize_zval_array(storage, item_address, zvalue, item);
					}
					zend_hash_index_update(ht, i, (void **) &item, sizeof(zval *), NULL);
				}
				if(item_address->dimension_count > 0) {
					qb_copy_elements_to_zval(storage, item_address, item);
				} else {
					qb_copy_element_to_zval(storage, item_address, item);
				}
				item_address->segment_offset += item_byte_count;
			}
		} else {
			qb_index_alias_scheme *scheme = address->index_alias_schemes[0];
			TSRMLS_FETCH();
			for(i = 0; i < dimension; i++) {
				zval **p_item, *item = NULL;
				zval *alias = qb_cstring_to_zval(scheme->aliases[i] TSRMLS_CC);
				p_item = Z_OBJ_GET_PROP_PTR_PTR(zvalue, alias);
				if(p_item) {
					SEPARATE_ZVAL_TO_MAKE_IS_REF(p_item);
					item = *p_item;
				} else {
					ALLOC_INIT_ZVAL(item);
					if(item_address->dimension_count > 0) {
						qb_initialize_zval_array(storage, item_address, zvalue, item);
					}
				}
				if(item_address->dimension_count > 0) {
					qb_copy_elements_to_zval(storage, item_address, item);
				} else {
					qb_copy_element_to_zval(storage, item_address, item);
				}
				if(!p_item) {
					Z_OBJ_WRITE_PROP(zvalue, alias, item);
					zval_ptr_dtor(&item);
				}
				item_address->segment_offset += item_byte_count;
			}
		}
	} else if(Z_TYPE_P(zvalue) == IS_STRING) {
		int8_t *memory = (int8_t *) Z_STRVAL_P(zvalue);
		uint32_t element_count = VALUE_IN(storage, U32, address->array_size_address);
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);
		memcpy(memory, ARRAY_IN(storage, I08, address), byte_count);
	} else if((stream = qb_get_file_stream(zvalue))) {
		// copy the content from the file 
		qb_copy_elements_to_file(storage, address, stream);
	} else if((image = qb_get_gd_image(zvalue))) {
		qb_copy_elements_to_gd_image(storage, address, image);
	}
}

static uint32_t qb_set_array_dimensions_from_storage_location(qb_storage *storage, qb_address *address, qb_storage *src_storage, qb_address *src_address) {
	if(src_address->dimension_count == address->dimension_count) {
		int32_t i;
		uint32_t array_size = 1;
		for(i = address->dimension_count - 1; i >= 0; i--) {
			qb_address *dimension_address = address->dimension_addresses[i];
			qb_address *caller_dimension_address = src_address->dimension_addresses[i];
			qb_address *array_size_address = address->array_size_addresses[i];
			uint32_t dimension = VALUE_IN(src_storage, U32, caller_dimension_address);
			uint32_t dimension_expected = VALUE_IN(storage, U32, dimension_address);

			array_size *= dimension;

			if(dimension > dimension_expected) {
				if(CONSTANT(dimension_address)) {
					qb_abort("Number of elements (%d) exceeds declared size of array (%d)", dimension, dimension_expected);
				} else {
					VALUE_IN(storage, U32, dimension_address) = dimension;
				}
				if(i != address->dimension_count - 1) {
					VALUE_IN(storage, U32, array_size_address) = array_size;
				}
			} else if(dimension < dimension_expected) {
				array_size = VALUE_IN(storage, U32, array_size_address);
			}
		}
		return array_size;
	} else {
		qb_abort("Dimension mismatch");
		return 0;
	}
}

void qb_copy_wrap_around(int8_t *memory, uint32_t filled_byte_count, uint32_t required_byte_count) {
	if(filled_byte_count) {
		while(filled_byte_count < required_byte_count) {
			uint32_t gap = (required_byte_count - filled_byte_count);
			uint32_t copy_count = (gap > filled_byte_count) ? filled_byte_count : gap;
			memcpy(memory + filled_byte_count, memory, copy_count);
			filled_byte_count += copy_count;
		}
	} else {
		memset(memory, 0, required_byte_count);
	}
}

void qb_copy_elements(uint32_t source_type, int8_t *restrict source_memory, uint32_t source_count, uint32_t dest_type, int8_t *restrict dest_memory, uint32_t dest_count) {
	uint32_t i, count = min(source_count, dest_count);
	switch(dest_type) {
		case QB_TYPE_S08: {
			switch(source_type) {
				case QB_TYPE_S08:
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_S16:
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(S08) *) dest_memory)[i] = (CTYPE(S08)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_U08: {
			switch(source_type) {
				case QB_TYPE_S08:
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16:
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(U08) *) dest_memory)[i] = (CTYPE(U08)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_S16: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16:
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(S16) *) dest_memory)[i] = (CTYPE(S16)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_U16: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16:
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(U16) *) dest_memory)[i] = (CTYPE(U16)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_S32: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(S32) *) dest_memory)[i] = (CTYPE(S32)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_U32: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32:
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(U32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(U32) *) dest_memory)[i] = (CTYPE(U32)) (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_S64: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(U32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(S64) *) dest_memory)[i] = (CTYPE(S64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_U64: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(U32) *) source_memory)[i]; break;
				case QB_TYPE_S64:
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(U64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(U64) *) dest_memory)[i] = (CTYPE(U64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_F32: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(U32) *) source_memory)[i]; break;
				case QB_TYPE_S64: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(U64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(F32) *) dest_memory)[i] = (CTYPE(F32)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
		case QB_TYPE_F64: {
			switch(source_type) {
				case QB_TYPE_S08: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(S08) *) source_memory)[i]; break;
				case QB_TYPE_U08: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(U08) *) source_memory)[i]; break;
				case QB_TYPE_S16: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(S16) *) source_memory)[i]; break;
				case QB_TYPE_U16: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(U16) *) source_memory)[i]; break;
				case QB_TYPE_S32: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(S32) *) source_memory)[i]; break;
				case QB_TYPE_U32: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(U32) *) source_memory)[i]; break;
				case QB_TYPE_S64: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(S64) *) source_memory)[i]; break;
				case QB_TYPE_U64: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(U64) *) source_memory)[i]; break;
				case QB_TYPE_F32: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(F32) *) source_memory)[i]; break;
				case QB_TYPE_F64: for(i = 0; i < count; i++) ((CTYPE(F64) *) dest_memory)[i] = (CTYPE(F64)) ((CTYPE(F64) *) source_memory)[i]; break;
			}
		}	break;
	}
	if(source_count < dest_count) {
		qb_copy_wrap_around(dest_memory, BYTE_COUNT(source_count, dest_type), BYTE_COUNT(dest_count, dest_type));
	}
}

static void qb_copy_elements_from_storage(qb_storage *storage, qb_address *address, qb_storage *src_storage, qb_address *src_address) {
	uint32_t src_element_count = SCALAR(src_address) ? 1 : ARRAY_SIZE_IN(src_storage, src_address);
	uint32_t dst_element_count = SCALAR(address) ? 1 : ARRAY_SIZE_IN(storage, address);
	qb_copy_elements(src_address->type, ARRAY_IN(src_storage, I08, src_address), src_element_count, address->type, ARRAY_IN(storage, I08, address), dst_element_count);
}

static void qb_copy_elements_to_storage(qb_storage *storage, qb_address *address, qb_storage *dst_storage, qb_address *dst_address) {
	uint32_t src_element_count = SCALAR(address) ? 1 : ARRAY_SIZE_IN(storage, address);
	uint32_t dst_element_count = SCALAR(dst_address) ? 1 : ARRAY_SIZE_IN(dst_storage, dst_address);
	qb_copy_elements(address->type, ARRAY_IN(storage, I08, address), src_element_count, dst_address->type, ARRAY_IN(dst_storage, I08, dst_address), dst_element_count);
}

static void qb_free_segment(qb_storage *storage, qb_memory_segment *segment TSRMLS_DC) {
	if(segment->flags & QB_SEGMENT_MAPPED) {
		php_stream *stream = segment->stream;
		qb_unmap_segment(storage, segment TSRMLS_CC);

		// set the file to the actual size if more bytes were allocated than needed
		if(segment->current_allocation != segment->byte_count) {
			php_stream_truncate_set_size(stream, segment->byte_count);
		}
		if(!(segment->flags & QB_SEGMENT_BORROWED)) {
			php_stream_close(stream);
			segment->flags &= ~QB_SEGMENT_BORROWED;
		}
	} else if(segment->flags & QB_SEGMENT_BORROWED) {
		segment->memory = NULL;
		segment->flags &= ~QB_SEGMENT_BORROWED;
	} else if(!(segment->flags & QB_SEGMENT_PREALLOCATED)) {
		if(segment->memory) {
			efree(segment->memory);
			segment->memory = NULL;
		}
	}
	segment->current_allocation = 0;
}

static void qb_resize_segment(qb_storage *storage, qb_memory_segment *segment, uint32_t byte_count TSRMLS_DC) {
	if(segment->flags & QB_SEGMENT_MAPPED) {
		php_stream *stream = segment->stream;
		qb_unmap_segment(storage, segment TSRMLS_CC);
		php_stream_truncate_set_size(stream, byte_count);
		if(!qb_map_segment_to_file(storage, segment, stream, TRUE, byte_count TSRMLS_CC)) {
			qb_abort("Cannot map '%s' into memory", stream->orig_path);
		}
	} else if(segment->flags & QB_SEGMENT_PREALLOCATED) {
		qb_abort("Cannot resize preallocated memory");
	} else {
		segment->memory = erealloc(segment->memory, byte_count);
	}
}

static void qb_transfer_value_from_zval(qb_storage *storage, qb_address *address, zval *zvalue, int32_t transfer_flags) {
	qb_memory_segment *segment = &storage->segments[address->segment_selector];
	uint32_t element_start_index = address->segment_offset;

	if(SCALAR(address)) {
		// make sure the address is valid
		/* TODO:
		if(address->segment_selector >= QB_SELECTOR_DYNAMIC_ARRAY_START) {			
			if(element_start_index + 1 > segment->current_allocation) {
				if(segment->flags & QB_SEGMENT_EXPANDABLE) {
					qb_resize_segment(cxt, segment, element_start_index + 1);
					segment->current_allocation = element_start_index + 1;
				} else {
					qb_abort_range_error(cxt, segment, element_start_index, 1, 0);
				}
			} 
		}
		*/

		// assign scalar value
		qb_copy_element_from_zval(storage, address, zvalue);
	} else {
		// determine the array's dimensions and check for out-of-bound condition
		/* TODO
		uint32_t element_count = qb_set_array_dimensions_from_zval(cxt, zvalue, address);
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);

		if(address->flags & QB_ADDRESS_SEGMENT) {
			segment->byte_count = byte_count;
			// if the segment doesn't have preallocated memory, see if it can borrow it from the zval
			if(!(segment->flags & QB_SEGMENT_PREALLOCATED) && (transfer_flags & (QB_TRANSFER_CAN_BORROW_MEMORY | QB_TRANSFER_CAN_SEIZE_MEMORY))) {
				php_stream *stream;
				if(Z_TYPE_P(zvalue) == IS_STRING) {
					// use memory from the string if it's long enough
					if((uint32_t) Z_STRLEN_P(zvalue) >= byte_count) {
						qb_free_segment(cxt, segment);
						segment->memory = (int8_t *) Z_STRVAL_P(zvalue);
						segment->current_allocation = byte_count;
						if(transfer_flags & QB_TRANSFER_CAN_BORROW_MEMORY) {
							segment->flags |= QB_SEGMENT_BORROWED;
						} else {
							Z_TYPE_P(zvalue) = IS_NULL;
						}
						return;
					}
				}
				if((stream = qb_get_file_stream(cxt, zvalue))) {
					// use memory mapped file if possible
					int32_t write_access = !READ_ONLY(address);
					uint32_t allocation = byte_count;
					if(allocation == 0) {
						// can't have a mapping that's zero
						if(write_access) {
							allocation = 1024;
						}
					}
					qb_free_segment(cxt, segment);
					if(qb_map_segment_to_file(cxt, segment, stream, write_access, allocation)) {
						segment->current_allocation = allocation;
						if(transfer_flags & QB_TRANSFER_CAN_BORROW_MEMORY) {
							segment->flags |= QB_SEGMENT_BORROWED;
						} else if(transfer_flags & QB_TRANSFER_CAN_SEIZE_MEMORY) {
							// the stream was taken from the zval
							Z_TYPE_P(zvalue) = IS_NULL;
						}
						return;
					}
				}
			}
		}
		
		if(element_start_index + byte_count > segment->current_allocation) {
			qb_resize_segment(cxt, segment, element_start_index + byte_count);
			segment->current_allocation = element_start_index + byte_count;
		}

		// copy values over
		qb_copy_elements_from_zval(cxt, zvalue, address);
		*/
	}
}

static void qb_transfer_value_from_storage_location(qb_interpreter_context *cxt, qb_storage *src_storage, qb_address *src_address, qb_address *address, uint32_t transfer_flags) {
	/* TODO
	// make sure the address is in bound in the caller segment
	if(src_address->segment_selector >= QB_SELECTOR_DYNAMIC_ARRAY_START) {
		qb_memory_segment *caller_segment = &src_storage->segments[src_address->segment_selector];
		uint32_t caller_element_start_index = src_address->segment_offset;
		uint32_t caller_element_count = SCALAR(src_address) ? 1 : ARRAY_SIZE_IN(src_storage, src_address);
		uint32_t caller_byte_count = BYTE_COUNT(caller_element_count, src_address->type);

		if(caller_element_start_index + caller_byte_count > caller_segment->byte_count) {
			if((caller_segment->flags & QB_SEGMENT_EXPANDABLE) && (transfer_flags & QB_TRANSFER_CAN_ENLARGE_SEGMENT)) {
				qb_enlarge_segment(cxt, caller_segment, caller_element_start_index + caller_byte_count);
			} else {
				qb_abort_range_error(cxt, caller_segment, caller_element_start_index, caller_element_count, cxt->function_call_line_number);
			}
		}
	}

	// set up the destination segment, if necessary
	if(address->flags & QB_ADDRESS_SEGMENT) {
		// set the dimensions of the destination address (or error out)
		qb_memory_segment *segment = &cxt->storage->segments[address->segment_selector];
		uint32_t element_count = qb_set_array_dimensions_from_src_address(cxt, src_storage, src_address, address); 
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);

		// see if we can just point to the memory in the caller storage
		if(!(segment->flags & QB_SEGMENT_PREALLOCATED) && transfer_flags & QB_TRANSFER_CAN_BORROW_MEMORY) {
			if(STORAGE_TYPE_MATCH(address->type, src_address->type)) {
				// use memory from the caller if it's larger enough
				if(ARRAY_SIZE_IN(src_storage, src_address) >= ARRAY_SIZE(address)) {
					if(segment->flags & QB_SEGMENT_EXPANDABLE) {
						if(EXPANDABLE_ARRAY(src_address)) {
							qb_memory_segment *caller_segment = &src_storage->segments[src_address->segment_selector];
							segment->memory = caller_segment->memory;
							segment->stream = caller_segment->stream;
							segment->current_allocation = caller_segment->current_allocation;
							segment->byte_count = byte_count;
							segment->flags |= (caller_segment->flags & QB_SEGMENT_MAPPED) | QB_SEGMENT_BORROWED;
							return;
						}
					} else {
						segment->memory = ARRAY_IN(src_storage, I08, src_address);
						segment->current_allocation = byte_count;
						segment->byte_count = byte_count;
						segment->flags |= QB_SEGMENT_BORROWED;
						return;
					}
				}
			}
		}

		// allocate memory for the segment
		if(segment->byte_count != byte_count) {
			qb_resize_segment(cxt, segment, byte_count);
		}

		// update the segment length and allocation count
		segment->byte_count = segment->current_allocation = byte_count; 
	}
	qb_copy_elements_from_src_address(cxt, src_storage, src_address, address);
	*/
}

static void qb_transfer_value_to_zval(qb_storage *storage, qb_address *address, zval *zvalue) {
	if(SCALAR(address)) {
		qb_copy_element_to_zval(storage, address, zvalue);
	} else {
		qb_memory_segment *segment = &storage->segments[address->segment_selector];
		uint32_t element_count = VALUE_IN(storage, U32, address->array_size_address);
		uint32_t byte_count = BYTE_COUNT(element_count, address->type);

		if(Z_TYPE_P(zvalue) == IS_NULL) {
			// create a string if the string flag is set, otherwise create an array
			if(address->flags & QB_ADDRESS_STRING) {
				ZVAL_EMPTY_STRING(zvalue);
			} else {
				qb_initialize_zval_array(storage, address, NULL, zvalue);
			}
		}
		if(Z_TYPE_P(zvalue) == IS_STRING) {
			// make sure the string is the right size
			if(Z_STRLEN_P(zvalue) != byte_count) {
				int8_t *memory = emalloc(byte_count + 1);
				memory[byte_count] = '\0';
				efree(Z_STRVAL_P(zvalue));
				Z_STRVAL_P(zvalue) = (char *) memory;
				Z_STRLEN_P(zvalue) = byte_count;
			}
		} 
		qb_copy_elements_to_zval(storage, address, zvalue);
	}
}

static void qb_transfer_value_to_storage_location(qb_interpreter_context *cxt, qb_address *address, qb_storage *dst_storage, qb_address *src_address) {
	/* TODO
	if(src_address->segment_selector >= QB_SELECTOR_DYNAMIC_ARRAY_START) {
		qb_memory_segment *segment = &cxt->storage->segments[address->segment_selector];
		qb_memory_segment *caller_segment = &dst_storage->segments[src_address->segment_selector];

		if(segment->flags & QB_SEGMENT_BORROWED) {
			// as memory was borrowed from the caller, content changes are there already
			if(segment->flags & QB_SEGMENT_EXPANDABLE) {
				// see if the size needs to be updated
				if(caller_segment->byte_count != segment->byte_count) {
					caller_segment->byte_count = segment->byte_count;
					if(src_address->dimension_count > 1) {
						// TODO: update the dimension as well
					}
				}
				if(caller_segment->memory != segment->memory) {
					// update the memory pointer as well in case it has changed
					caller_segment->memory = segment->memory;
				}
			}
			segment->flags &= ~QB_SEGMENT_BORROWED;
			segment->memory = NULL;
			segment->stream = NULL;
			return;
		} else {
			uint32_t element_start_index = src_address->segment_offset;
			uint32_t element_count = SCALAR(address) ? 1 : ARRAY_SIZE(address);
			uint32_t byte_count = BYTE_COUNT(element_count, address->type);

			if(element_start_index + byte_count > caller_segment->byte_count) {
				if(caller_segment->flags & QB_SEGMENT_EXPANDABLE) {
					// expand the caller segment to accommodate
					qb_enlarge_segment(cxt, caller_segment, element_start_index + element_count);
				} else {
					qb_abort_range_error(cxt, caller_segment, element_start_index, element_count, cxt->function_call_line_number);
				}
			}
		}
	}
	qb_copy_elements_to_src_address(cxt, address, dst_storage, src_address);
	*/
}