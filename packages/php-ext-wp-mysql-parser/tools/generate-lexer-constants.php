<?php

$root = dirname( __DIR__, 3 );
require_once $root . '/src/mysql/class-wp-mysql-lexer.php';

$reflection = new ReflectionClass( 'WP_MySQL_Lexer' );
$constants  = $reflection->getConstants();
$target     = __DIR__ . '/../src/lexer_constants.rs';

function rust_string_literal( string $value ): string {
	$literal = '"';
	$length  = strlen( $value );
	for ( $i = 0; $i < $length; $i++ ) {
		$byte = ord( $value[ $i ] );
		if ( 9 === $byte ) {
			$literal .= '\\t';
		} elseif ( 10 === $byte ) {
			$literal .= '\\n';
		} elseif ( 13 === $byte ) {
			$literal .= '\\r';
		} elseif ( 34 === $byte ) {
			$literal .= '\\"';
		} elseif ( 92 === $byte ) {
			$literal .= '\\\\';
		} elseif ( $byte < 32 || $byte >= 127 ) {
			$literal .= sprintf( '\\x%02x', $byte );
		} else {
			$literal .= $value[ $i ];
		}
	}
	$literal .= '"';
	return $literal;
}

function rust_value_literal( $value ): string {
	if ( is_int( $value ) ) {
		return $value . 'i64';
	}

	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}

	if ( is_string( $value ) ) {
		return rust_string_literal( $value );
	}

	throw new RuntimeException( 'Unsupported constant value type: ' . gettype( $value ) );
}

function rust_array_function_name( string $constant_name ): string {
	return 'array_' . strtolower( preg_replace( '/[^A-Za-z0-9_]/', '_', $constant_name ) );
}

function rust_array_function( string $constant_name, array $value ): string {
	$name  = rust_array_function_name( $constant_name );
	$rust  = "fn $name() -> ZBox<ZendHashTable> {\n";
	$rust .= "\tlet mut array = persistent_array( " . count( $value ) . " );\n";

	foreach ( $value as $key => $item ) {
		$item_literal = rust_value_literal( $item );
		if ( is_int( $key ) ) {
			$rust .= "\tarray.insert_at_index( {$key}i64, {$item_literal} ).unwrap();\n";
		} else {
			$key_literal = rust_string_literal( $key );
			$rust       .= "\tarray.insert( {$key_literal}, {$item_literal} ).unwrap();\n";
		}
	}

	$rust .= "\tfreeze_array( &mut array );\n";
	$rust .= "\tarray\n";
	$rust .= "}\n\n";
	return $rust;
}

$rust = <<<RUST
#![allow(dead_code)]

use std::mem;
use std::ptr;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::builders::ClassBuilder;
use ext_php_rs::ffi::{zval, HashTable};
use ext_php_rs::types::ZendHashTable;

type DtorFunc = Option<unsafe extern "C" fn(*mut zval)>;
const GC_IMMUTABLE: u32 = 1 << 6;

extern "C" {
	fn _zend_hash_init(ht: *mut HashTable, nSize: u32, pDestructor: DtorFunc, persistent: bool);
}

fn persistent_array(capacity: usize) -> ZBox<ZendHashTable> {
	unsafe {
		let pointer = libc::malloc(mem::size_of::<ZendHashTable>()) as *mut ZendHashTable;
		if pointer.is_null() {
			panic!("Failed to allocate persistent Zend array");
		}
		ptr::write_bytes(pointer, 0, 1);
		_zend_hash_init(pointer, capacity as u32, None, true);
		ZBox::from_raw(pointer)
	}
}

fn freeze_array(array: &mut ZendHashTable) {
	unsafe {
		array.gc.u.type_info |= GC_IMMUTABLE;
	}
}

RUST;

foreach ( $constants as $name => $value ) {
	if ( is_array( $value ) ) {
		$rust .= rust_array_function( $name, $value );
	}
}

$rust .= "pub const SCALAR_INT_CONSTANTS: &[(&str, i64)] = &[\n";
foreach ( $constants as $name => $value ) {
	if ( is_int( $value ) ) {
		$rust .= "\t( \"$name\", {$value}i64 ),\n";
	}
}
$rust .= "];\n\n";

foreach ( $constants as $name => $value ) {
	if ( is_int( $value ) ) {
		$rust .= "pub const $name: i64 = {$value}i64;\n";
	} elseif ( is_bool( $value ) ) {
		$rust .= 'pub const ' . $name . ': bool = ' . ( $value ? 'true' : 'false' ) . ";\n";
	} elseif ( is_string( $value ) ) {
		$rust .= 'pub const ' . $name . ': &str = ' . rust_string_literal( $value ) . ";\n";
	}
}
$rust .= "\n";

$rust .= "pub const KEYWORD_TOKENS: &[(&str, i64)] = &[\n";
foreach ( $constants['TOKENS'] as $key => $value ) {
	$rust .= "\t( " . rust_string_literal( $key ) . ", {$value}i64 ),\n";
}
$rust .= "];\n\n";

$rust .= "pub const VERSION_RULES: &[(i64, i64)] = &[\n";
foreach ( $constants['VERSIONS'] as $key => $value ) {
	$rust .= "\t( {$key}i64, {$value}i64 ),\n";
}
$rust .= "];\n\n";

$rust .= "pub const FUNCTION_TOKENS: &[i64] = &[\n";
foreach ( $constants['FUNCTIONS'] as $key => $value ) {
	$rust .= "\t{$key}i64,\n";
}
$rust .= "];\n\n";

$rust .= "pub const TOKEN_SYNONYMS: &[(i64, i64)] = &[\n";
foreach ( $constants['SYNONYMS'] as $key => $value ) {
	$rust .= "\t( {$key}i64, {$value}i64 ),\n";
}
$rust .= "];\n\n";

$rust .= "pub const UNDERSCORE_CHARSET_NAMES: &[&str] = &[\n";
foreach ( $constants['UNDERSCORE_CHARSETS'] as $key => $value ) {
	$rust .= "\t" . rust_string_literal( $key ) . ",\n";
}
$rust .= "];\n\n";

$rust .= <<<'RUST'
pub fn token_id(name: &str) -> Option<i64> {
	SCALAR_INT_CONSTANTS
		.iter()
		.find_map(|(constant_name, id)| (*constant_name == name).then_some(*id))
}

pub fn token_name(id: i64) -> Option<&'static str> {
	SCALAR_INT_CONSTANTS
		.iter()
		.rev()
		.find_map(|(constant_name, token_id)| (*token_id == id).then_some(*constant_name))
}

pub fn keyword_token(keyword: &str) -> Option<i64> {
	KEYWORD_TOKENS
		.iter()
		.find_map(|(candidate, id)| (*candidate == keyword).then_some(*id))
}

pub fn version_rule(token_id: i64) -> Option<i64> {
	VERSION_RULES
		.iter()
		.find_map(|(candidate, version)| (*candidate == token_id).then_some(*version))
}

pub fn is_function_token(token_id: i64) -> bool {
	FUNCTION_TOKENS.contains(&token_id)
}

pub fn token_synonym(token_id: i64) -> Option<i64> {
	TOKEN_SYNONYMS
		.iter()
		.find_map(|(candidate, synonym)| (*candidate == token_id).then_some(*synonym))
}

pub fn is_underscore_charset(name: &str) -> bool {
	UNDERSCORE_CHARSET_NAMES.contains(&name)
}

RUST;

$rust .= <<<RUST

pub fn register_lexer_constants(mut builder: ClassBuilder) -> ClassBuilder {

RUST;

foreach ( $constants as $name => $value ) {
	if ( is_array( $value ) ) {
		$function_name = rust_array_function_name( $name );
		$rust         .= "\tbuilder = builder.constant( \"$name\", $function_name(), &[] ).unwrap();\n";
	} elseif ( is_int( $value ) || is_bool( $value ) || is_string( $value ) ) {
		$rust_value = rust_value_literal( $value );
		$rust      .= "\tbuilder = builder.constant( \"$name\", $rust_value, &[] ).unwrap();\n";
	}
}

$rust .= "\tbuilder\n}\n";

file_put_contents( $target, $rust );
