#![cfg_attr(windows, feature(abi_vectorcall))]

use std::collections::{HashMap, HashSet};
use std::os::raw::c_char;
use std::ptr;
use std::sync::Arc;

use ext_php_rs::convert::{FromZval, IntoZval, IntoZvalDyn};
use ext_php_rs::exception::{PhpException, PhpResult};
use ext_php_rs::ffi::{zend_class_entry, zend_object, zval};
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendCallable, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ModuleEntry};
use ext_php_rs::{info_table_end, info_table_row, info_table_start};

mod lexer_constants;
use lexer_constants as lex;
use lexer_constants::register_lexer_constants;

const SQL_MODE_HIGH_NOT_PRECEDENCE: i64 = 1;
const SQL_MODE_PIPES_AS_CONCAT: i64 = 2;
const SQL_MODE_IGNORE_SPACE: i64 = 4;
const SQL_MODE_NO_BACKSLASH_ESCAPES: i64 = 8;
const STACK_RED_ZONE: usize = 128 * 1024;
const STACK_GROW_SIZE: usize = 8 * 1024 * 1024;

extern "C" {
    fn zend_update_property(
        scope: *mut zend_class_entry,
        object: *mut zend_object,
        name: *const c_char,
        name_length: usize,
        value: *mut zval,
    );
}

#[derive(Clone)]
struct BinaryString(Vec<u8>);

impl IntoZval for BinaryString {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> ext_php_rs::error::Result<()> {
        zv.set_binary(self.0);
        Ok(())
    }
}

fn php_error(message: impl ToString) -> PhpException {
    PhpException::default(message.to_string())
}

fn php_function(name: &str) -> PhpResult<ZendCallable<'_>> {
    ZendCallable::try_from_name(name).map_err(php_error)
}

struct PhpClasses {
    parser_token: &'static ClassEntry,
    mysql_token: &'static ClassEntry,
    native_parser_node: &'static ClassEntry,
}

fn php_classes() -> PhpResult<PhpClasses> {
    Ok(PhpClasses {
        parser_token: ClassEntry::try_find("WP_Parser_Token")
            .ok_or_else(|| php_error("Missing WP_Parser_Token class"))?,
        mysql_token: ClassEntry::try_find("WP_MySQL_Token")
            .ok_or_else(|| php_error("Missing WP_MySQL_Token class"))?,
        native_parser_node: ClassEntry::try_find("WP_MySQL_Native_Parser_Node")
            .ok_or_else(|| php_error("Missing WP_MySQL_Native_Parser_Node class"))?,
    })
}

fn update_object_property(
    object: &mut ZendObject,
    scope: &ClassEntry,
    name: &str,
    value: impl IntoZval,
) -> PhpResult<()> {
    let mut value = value.into_zval(false).map_err(php_error)?;
    unsafe {
        zend_update_property(
            ptr::from_ref(scope).cast_mut(),
            ptr::from_mut(object),
            name.as_ptr().cast::<c_char>(),
            name.len(),
            ptr::from_mut(&mut value),
        );
    }
    Ok(())
}

fn create_mysql_token(sql_zval: &Zval, token: TokenInfo, no_backslash: bool) -> PhpResult<Zval> {
    let classes = php_classes()?;
    create_mysql_token_with_classes(sql_zval, token, no_backslash, &classes)
}

fn create_mysql_token_with_classes(
    sql_zval: &Zval,
    token: TokenInfo,
    no_backslash: bool,
    classes: &PhpClasses,
) -> PhpResult<Zval> {
    let id = token.id;
    let start = i64::try_from(token.start).map_err(php_error)?;
    let length = i64::try_from(token.end.saturating_sub(token.start)).map_err(php_error)?;
    let mut object = classes.mysql_token.new();

    update_object_property(&mut object, classes.parser_token, "id", id)?;
    update_object_property(&mut object, classes.parser_token, "start", start)?;
    update_object_property(&mut object, classes.parser_token, "length", length)?;
    update_object_property(
        &mut object,
        classes.parser_token,
        "input",
        sql_zval.shallow_clone(),
    )?;
    update_object_property(
        &mut object,
        classes.mysql_token,
        "sql_mode_no_backslash_escapes_enabled",
        no_backslash,
    )?;

    object.into_zval(false).map_err(php_error)
}

fn sql_modes_mask(sql_modes: &[String]) -> i64 {
    let mut mask = 0;
    for sql_mode in sql_modes {
        match sql_mode.to_ascii_uppercase().as_str() {
            "HIGH_NOT_PRECEDENCE" => mask |= SQL_MODE_HIGH_NOT_PRECEDENCE,
            "PIPES_AS_CONCAT" => mask |= SQL_MODE_PIPES_AS_CONCAT,
            "IGNORE_SPACE" => mask |= SQL_MODE_IGNORE_SPACE,
            "NO_BACKSLASH_ESCAPES" => mask |= SQL_MODE_NO_BACKSLASH_ESCAPES,
            _ => {}
        }
    }
    mask
}

fn zval_to_weak_string_bytes(zv: &Zval) -> PhpResult<Vec<u8>> {
    if let Some(bytes) = zv.binary::<u8>() {
        return Ok(bytes);
    }
    if zv.is_null() || zv.is_false() {
        return Ok(Vec::new());
    }
    if zv.is_true() {
        return Ok(b"1".to_vec());
    }
    if let Some(value) = zv.long() {
        return Ok(value.to_string().into_bytes());
    }
    if let Some(value) = zv.double() {
        return Ok(value.to_string().into_bytes());
    }
    Err(php_error("Invalid value given for argument `sql`."))
}

fn byte_in(byte: u8, mask: &[u8]) -> bool {
    mask.contains(&byte)
}

fn span_while(bytes: &[u8], mut pos: usize, predicate: impl Fn(u8) -> bool) -> usize {
    while pos < bytes.len() && predicate(bytes[pos]) {
        pos += 1;
    }
    pos
}

fn span_until(bytes: &[u8], mut pos: usize, needles: &[u8]) -> usize {
    while pos < bytes.len() && !needles.contains(&bytes[pos]) {
        pos += 1;
    }
    pos
}

fn bytes_ascii_upper(bytes: &[u8]) -> String {
    bytes
        .iter()
        .map(|byte| byte.to_ascii_uppercase() as char)
        .collect()
}

fn bytes_ascii_lower(bytes: &[u8]) -> String {
    bytes
        .iter()
        .map(|byte| byte.to_ascii_lowercase() as char)
        .collect()
}

#[derive(Clone, Copy)]
struct TokenInfo {
    id: i64,
    start: usize,
    end: usize,
}

#[php_class]
#[php(name = "WP_MySQL_Native_Token_Stream")]
pub struct WpMySqlNativeTokenStream {
    sql_zval: Zval,
    tokens: Vec<TokenInfo>,
    no_backslash: bool,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeTokenStream {
    pub fn count(&self) -> usize {
        self.tokens.len()
    }
}

#[php_class]
#[php(name = "WP_MySQL_Native_Lexer", modifier = "register_lexer_constants")]
pub struct WpMySqlNativeLexer {
    sql: Vec<u8>,
    sql_zval: Zval,
    mysql_version: i64,
    sql_modes: i64,
    bytes_already_read: usize,
    token_starts_at: usize,
    token_type: Option<i64>,
    in_mysql_comment: bool,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeLexer {
    pub fn __construct(
        sql: &Zval,
        mysql_version: Option<i64>,
        sql_modes: Option<Vec<String>>,
    ) -> PhpResult<Self> {
        let mysql_version = mysql_version.unwrap_or(80038);
        let sql_modes = sql_modes.unwrap_or_default();
        let sql = zval_to_weak_string_bytes(sql)?;
        let sql_zval = BinaryString(sql.clone())
            .into_zval(false)
            .map_err(php_error)?;

        Ok(Self {
            sql,
            sql_zval,
            mysql_version,
            sql_modes: sql_modes_mask(&sql_modes),
            bytes_already_read: 0,
            token_starts_at: 0,
            token_type: None,
            in_mysql_comment: false,
        })
    }

    pub fn next_token(&mut self) -> bool {
        if self.token_type == Some(lex::EOF)
            || (self.token_type.is_none() && self.bytes_already_read > 0)
        {
            self.token_type = None;
            return false;
        }

        loop {
            self.token_starts_at = self.bytes_already_read;
            self.token_type = self.read_next_token();
            if !matches!(
                self.token_type,
                Some(
                    lex::WHITESPACE
                        | lex::COMMENT
                        | lex::MYSQL_COMMENT_START
                        | lex::MYSQL_COMMENT_END
                )
            ) {
                break;
            }
        }

        self.token_type.is_some()
    }

    pub fn get_token(&mut self) -> PhpResult<Zval> {
        match self.current_token_info() {
            Some(token) => self.create_token(token),
            None => Ok(Zval::null()),
        }
    }

    pub fn remaining_tokens(&mut self) -> PhpResult<Vec<Zval>> {
        let mut tokens = Vec::new();
        while self.next_token() {
            if let Some(token) = self.current_token_info() {
                tokens.push(self.create_token(token)?);
            }
        }
        Ok(tokens)
    }

    pub fn native_token_stream(&mut self) -> WpMySqlNativeTokenStream {
        let mut tokens = Vec::new();
        while self.next_token() {
            if let Some(token) = self.current_token_info() {
                tokens.push(token);
            }
        }

        WpMySqlNativeTokenStream {
            sql_zval: self.sql_zval.shallow_clone(),
            tokens,
            no_backslash: self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES),
        }
    }

    pub fn get_mysql_version(&self) -> i64 {
        self.mysql_version
    }

    pub fn is_sql_mode_active(&self, mode: i64) -> bool {
        (self.sql_modes & mode) != 0
    }

    pub fn get_token_id(token_name: String) -> Option<i64> {
        lex::token_id(&token_name)
    }

    pub fn get_token_name(token_id: i64) -> Option<String> {
        lex::token_name(token_id).map(ToOwned::to_owned)
    }
}

impl WpMySqlNativeLexer {
    fn current_token_info(&self) -> Option<TokenInfo> {
        self.token_type.map(|id| TokenInfo {
            id,
            start: self.token_starts_at,
            end: self.bytes_already_read,
        })
    }

    fn create_token(&self, token: TokenInfo) -> PhpResult<Zval> {
        create_mysql_token(
            &self.sql_zval,
            token,
            self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES),
        )
    }

    fn read_next_token(&mut self) -> Option<i64> {
        let byte = self.byte_at(self.bytes_already_read);
        let next_byte = self.byte_at(self.bytes_already_read + 1);

        let token = match byte {
            Some(b'\'' | b'"' | b'`') => self.read_quoted_text(),
            Some(byte) if byte.is_ascii_digit() => self.read_number(),
            Some(b'.') => {
                if next_byte.is_some_and(|byte| byte.is_ascii_digit()) {
                    self.read_number()
                } else {
                    self.bytes_already_read += 1;
                    Some(lex::DOT_SYMBOL)
                }
            }
            Some(b'=') => {
                self.bytes_already_read += 1;
                Some(lex::EQUAL_OPERATOR)
            }
            Some(b':') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'=') {
                    self.bytes_already_read += 1;
                    Some(lex::ASSIGN_OPERATOR)
                } else {
                    Some(lex::COLON_SYMBOL)
                }
            }
            Some(b'<') => self.read_less_than(next_byte),
            Some(b'>') => self.read_greater_than(next_byte),
            Some(b'!') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'=') {
                    self.bytes_already_read += 1;
                    Some(lex::NOT_EQUAL_OPERATOR)
                } else {
                    Some(lex::LOGICAL_NOT_OPERATOR)
                }
            }
            Some(b'+') => {
                self.bytes_already_read += 1;
                Some(lex::PLUS_OPERATOR)
            }
            Some(b'-') => self.read_minus(next_byte),
            Some(b'*') => self.read_star(next_byte),
            Some(b'/') => self.read_slash(next_byte),
            Some(b'%') => {
                self.bytes_already_read += 1;
                Some(lex::MOD_OPERATOR)
            }
            Some(b'&') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'&') {
                    self.bytes_already_read += 1;
                    Some(lex::LOGICAL_AND_OPERATOR)
                } else {
                    Some(lex::BITWISE_AND_OPERATOR)
                }
            }
            Some(b'^') => {
                self.bytes_already_read += 1;
                Some(lex::BITWISE_XOR_OPERATOR)
            }
            Some(b'|') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'|') {
                    self.bytes_already_read += 1;
                    if self.is_sql_mode_active(SQL_MODE_PIPES_AS_CONCAT) {
                        Some(lex::CONCAT_PIPES_SYMBOL)
                    } else {
                        Some(lex::LOGICAL_OR_OPERATOR)
                    }
                } else {
                    Some(lex::BITWISE_OR_OPERATOR)
                }
            }
            Some(b'~') => {
                self.bytes_already_read += 1;
                Some(lex::BITWISE_NOT_OPERATOR)
            }
            Some(b',') => {
                self.bytes_already_read += 1;
                Some(lex::COMMA_SYMBOL)
            }
            Some(b';') => {
                self.bytes_already_read += 1;
                Some(lex::SEMICOLON_SYMBOL)
            }
            Some(b'(') => {
                self.bytes_already_read += 1;
                Some(lex::OPEN_PAR_SYMBOL)
            }
            Some(b')') => {
                self.bytes_already_read += 1;
                Some(lex::CLOSE_PAR_SYMBOL)
            }
            Some(b'{') => {
                self.bytes_already_read += 1;
                Some(lex::OPEN_CURLY_SYMBOL)
            }
            Some(b'}') => {
                self.bytes_already_read += 1;
                Some(lex::CLOSE_CURLY_SYMBOL)
            }
            Some(b'@') => self.read_at(next_byte),
            Some(b'?') => {
                self.bytes_already_read += 1;
                Some(lex::PARAM_MARKER)
            }
            Some(b'\\') => {
                self.bytes_already_read += 1;
                if next_byte == Some(b'N') {
                    self.bytes_already_read += 1;
                    Some(lex::NULL2_SYMBOL)
                } else {
                    None
                }
            }
            Some(b'#') => Some(self.read_line_comment()),
            Some(byte) if byte_in(byte, lex::WHITESPACE_MASK.as_bytes()) => {
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte_in(byte, lex::WHITESPACE_MASK.as_bytes())
                });
                Some(lex::WHITESPACE)
            }
            Some(b'x' | b'X' | b'b' | b'B') if next_byte == Some(b'\'') => self.read_number(),
            Some(b'n' | b'N') if next_byte == Some(b'\'') => {
                self.bytes_already_read += 1;
                let token = self.read_quoted_text();
                if token == Some(lex::SINGLE_QUOTED_TEXT) {
                    Some(lex::NCHAR_TEXT)
                } else {
                    token
                }
            }
            None => Some(lex::EOF),
            Some(_) => {
                let started_at = self.bytes_already_read;
                let mut token = self.read_identifier();
                if token == Some(lex::IDENTIFIER) {
                    if started_at > 0 && self.byte_at(started_at - 1) == Some(b'.') {
                        token = Some(lex::IDENTIFIER);
                    } else if self.byte_at(started_at) == Some(b'_')
                        && lex::is_underscore_charset(&bytes_ascii_lower(
                            &self.sql[self.token_starts_at..self.bytes_already_read],
                        ))
                    {
                        token = Some(lex::UNDERSCORE_CHARSET);
                    } else {
                        let identifier =
                            self.sql[self.token_starts_at..self.bytes_already_read].to_vec();
                        token = Some(self.determine_identifier_or_keyword_type(&identifier));
                    }
                }
                token
            }
        };

        token
    }

    fn byte_at(&self, pos: usize) -> Option<u8> {
        self.sql.get(pos).copied()
    }

    fn read_less_than(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'=') {
            self.bytes_already_read += 1;
            if self.byte_at(self.bytes_already_read) == Some(b'>') {
                self.bytes_already_read += 1;
                Some(lex::NULL_SAFE_EQUAL_OPERATOR)
            } else {
                Some(lex::LESS_OR_EQUAL_OPERATOR)
            }
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 1;
            Some(lex::NOT_EQUAL_OPERATOR)
        } else if next_byte == Some(b'<') {
            self.bytes_already_read += 1;
            Some(lex::SHIFT_LEFT_OPERATOR)
        } else {
            Some(lex::LESS_THAN_OPERATOR)
        }
    }

    fn read_greater_than(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'=') {
            self.bytes_already_read += 1;
            Some(lex::GREATER_OR_EQUAL_OPERATOR)
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 1;
            Some(lex::SHIFT_RIGHT_OPERATOR)
        } else {
            Some(lex::GREATER_THAN_OPERATOR)
        }
    }

    fn read_minus(&mut self, next_byte: Option<u8>) -> Option<i64> {
        if next_byte == Some(b'-')
            && self.bytes_already_read + 2 < self.sql.len()
            && byte_in(
                self.sql[self.bytes_already_read + 2],
                lex::WHITESPACE_MASK.as_bytes(),
            )
        {
            Some(self.read_line_comment())
        } else if next_byte == Some(b'>') {
            self.bytes_already_read += 2;
            if self.byte_at(self.bytes_already_read) == Some(b'>') {
                self.bytes_already_read += 1;
                if self.mysql_version >= 50713 {
                    Some(lex::JSON_UNQUOTED_SEPARATOR_SYMBOL)
                } else {
                    None
                }
            } else if self.mysql_version >= 50708 {
                Some(lex::JSON_SEPARATOR_SYMBOL)
            } else {
                None
            }
        } else {
            self.bytes_already_read += 1;
            Some(lex::MINUS_OPERATOR)
        }
    }

    fn read_star(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'/') && self.in_mysql_comment {
            self.bytes_already_read += 1;
            self.in_mysql_comment = false;
            Some(lex::MYSQL_COMMENT_END)
        } else {
            Some(lex::MULT_OPERATOR)
        }
    }

    fn read_slash(&mut self, next_byte: Option<u8>) -> Option<i64> {
        if next_byte == Some(b'*') {
            if self.byte_at(self.bytes_already_read + 2) == Some(b'!') {
                Some(self.read_mysql_comment())
            } else {
                self.bytes_already_read += 2;
                self.read_comment_content();
                Some(lex::COMMENT)
            }
        } else {
            self.bytes_already_read += 1;
            Some(lex::DIV_OPERATOR)
        }
    }

    fn read_at(&mut self, next_byte: Option<u8>) -> Option<i64> {
        self.bytes_already_read += 1;
        if next_byte == Some(b'@') {
            self.bytes_already_read += 1;
            return Some(lex::AT_AT_SIGN_SYMBOL);
        }

        let start = self.bytes_already_read;
        self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
            byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'.' | b'$')
        });
        if self.bytes_already_read > start {
            Some(lex::AT_TEXT_SUFFIX)
        } else {
            Some(lex::AT_SIGN_SYMBOL)
        }
    }

    fn read_identifier(&mut self) -> Option<i64> {
        let started_at = self.bytes_already_read;
        loop {
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'$')
            });

            let byte_1 = self.byte_at(self.bytes_already_read).unwrap_or(0);
            if !(0xC2..=0xEF).contains(&byte_1) {
                break;
            }

            let byte_2 = self.byte_at(self.bytes_already_read + 1).unwrap_or(0);
            if byte_1 <= 0xDF && (0x80..=0xBF).contains(&byte_2) {
                self.bytes_already_read += 2;
                continue;
            }

            let byte_3 = self.byte_at(self.bytes_already_read + 2).unwrap_or(0);
            if byte_1 <= 0xEF
                && (0x80..=0xBF).contains(&byte_2)
                && (0x80..=0xBF).contains(&byte_3)
                && !(byte_1 == 0xED && byte_2 >= 0xA0)
                && !(byte_1 == 0xE0 && byte_2 < 0xA0)
            {
                self.bytes_already_read += 3;
                continue;
            }

            break;
        }

        (self.bytes_already_read > started_at).then_some(lex::IDENTIFIER)
    }

    fn read_number(&mut self) -> Option<i64> {
        let byte = self.byte_at(self.bytes_already_read);
        let next_byte = self.byte_at(self.bytes_already_read + 1);
        let third_byte = self.byte_at(self.bytes_already_read + 2);
        let mut token_type;

        if (byte == Some(b'0')
            && next_byte == Some(b'x')
            && third_byte.is_some_and(|byte| byte_in(byte, lex::HEX_DIGIT_MASK.as_bytes())))
            || (matches!(byte, Some(b'x' | b'X')) && next_byte == Some(b'\''))
        {
            let is_quoted = next_byte == Some(b'\'');
            self.bytes_already_read += 2;
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte_in(byte, lex::HEX_DIGIT_MASK.as_bytes())
            });
            if is_quoted {
                if self.byte_at(self.bytes_already_read) != Some(b'\'') {
                    return None;
                }
                self.bytes_already_read += 1;
            }
            token_type = lex::HEX_NUMBER;
        } else if (byte == Some(b'0')
            && next_byte == Some(b'b')
            && matches!(third_byte, Some(b'0' | b'1')))
            || (matches!(byte, Some(b'b' | b'B')) && next_byte == Some(b'\''))
        {
            let is_quoted = next_byte == Some(b'\'');
            self.bytes_already_read += 2;
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                matches!(byte, b'0' | b'1')
            });
            if is_quoted {
                if self.byte_at(self.bytes_already_read) != Some(b'\'') {
                    return None;
                }
                self.bytes_already_read += 1;
            }
            token_type = lex::BIN_NUMBER;
        } else {
            self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                byte.is_ascii_digit()
            });
            token_type = lex::INT_NUMBER;

            if self.byte_at(self.bytes_already_read) == Some(b'.') {
                self.bytes_already_read += 1;
                token_type = lex::DECIMAL_NUMBER;
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte.is_ascii_digit()
                });
            }

            let exponent = self.byte_at(self.bytes_already_read);
            let next = self.byte_at(self.bytes_already_read + 1);
            let has_exponent = matches!(exponent, Some(b'e' | b'E'))
                && (next.is_some_and(|byte| byte.is_ascii_digit())
                    || (matches!(next, Some(b'+' | b'-'))
                        && self
                            .byte_at(self.bytes_already_read + 2)
                            .is_some_and(|byte| byte.is_ascii_digit())));
            if has_exponent {
                self.bytes_already_read += 2;
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte.is_ascii_digit()
                });
                token_type = lex::FLOAT_NUMBER;
            }
        }

        let token_bytes = &self.sql[self.token_starts_at..self.bytes_already_read];
        let possible_identifier_prefix = token_type == lex::INT_NUMBER
            || (token_bytes.first() == Some(&b'0')
                && matches!(token_bytes.get(1), Some(b'b' | b'x')));

        if possible_identifier_prefix && self.read_identifier() == Some(lex::IDENTIFIER) {
            token_type = lex::IDENTIFIER;
        }

        if token_type == lex::INT_NUMBER {
            let mut bytes = &self.sql[self.token_starts_at..self.bytes_already_read];
            if bytes.len() < 10 {
                return Some(lex::INT_NUMBER);
            }
            while bytes.first() == Some(&b'0') {
                bytes = &bytes[1..];
            }
            let len = bytes.len();
            return Some(if len < 10 {
                lex::INT_NUMBER
            } else if len == 10 {
                if bytes > b"2147483647" {
                    lex::LONG_NUMBER
                } else {
                    lex::INT_NUMBER
                }
            } else if len < 19 {
                lex::LONG_NUMBER
            } else if len == 19 {
                if bytes > b"9223372036854775807" {
                    lex::ULONGLONG_NUMBER
                } else {
                    lex::LONG_NUMBER
                }
            } else if len == 20 {
                if bytes > b"18446744073709551615" {
                    lex::DECIMAL_NUMBER
                } else {
                    lex::ULONGLONG_NUMBER
                }
            } else {
                lex::DECIMAL_NUMBER
            });
        }

        Some(token_type)
    }

    fn read_quoted_text(&mut self) -> Option<i64> {
        let quote = self.byte_at(self.bytes_already_read)?;
        self.bytes_already_read += 1;
        let no_backslash_escapes = self.is_sql_mode_active(SQL_MODE_NO_BACKSLASH_ESCAPES);
        let mut at = self.bytes_already_read;

        loop {
            at = span_until(&self.sql, at, &[quote]);

            if !no_backslash_escapes {
                let mut i = 0usize;
                while at > i && self.byte_at(at - i - 1) == Some(b'\\') {
                    i += 1;
                }
                if i % 2 == 1 {
                    at += 1;
                    if at > self.sql.len() {
                        return None;
                    }
                    continue;
                }
            }

            if self.byte_at(at) != Some(quote) {
                return None;
            }

            if self.byte_at(at + 1) == Some(quote) {
                at += 2;
                continue;
            }
            break;
        }

        self.bytes_already_read = at + 1;
        Some(match quote {
            b'`' => lex::BACK_TICK_QUOTED_ID,
            b'"' => lex::DOUBLE_QUOTED_TEXT,
            _ => lex::SINGLE_QUOTED_TEXT,
        })
    }

    fn read_line_comment(&mut self) -> i64 {
        self.bytes_already_read = span_until(&self.sql, self.bytes_already_read, b"\r\n");
        lex::COMMENT
    }

    fn read_mysql_comment(&mut self) -> i64 {
        self.bytes_already_read += 3;
        let digit_start = self.bytes_already_read;
        let digit_end =
            span_while(&self.sql, digit_start, |byte| byte.is_ascii_digit()).min(digit_start + 5);
        let digit_count = digit_end - digit_start;
        let is_version_comment = digit_count == 5;
        let version = if is_version_comment {
            std::str::from_utf8(&self.sql[digit_start..digit_end])
                .ok()
                .and_then(|value| value.parse::<i64>().ok())
                .unwrap_or(0)
        } else {
            0
        };

        if self.mysql_version < version {
            self.read_comment_content();
            lex::COMMENT
        } else {
            self.bytes_already_read += digit_count;
            self.in_mysql_comment = true;
            lex::MYSQL_COMMENT_START
        }
    }

    fn read_comment_content(&mut self) {
        loop {
            self.bytes_already_read = span_until(&self.sql, self.bytes_already_read, b"*");
            self.bytes_already_read += 1;
            match self.byte_at(self.bytes_already_read) {
                None => break,
                Some(b'/') => {
                    self.bytes_already_read += 1;
                    break;
                }
                _ => {}
            }
        }
    }

    fn determine_identifier_or_keyword_type(&mut self, value: &[u8]) -> i64 {
        let upper = bytes_ascii_upper(value);
        let mut token_type = match lex::keyword_token(&upper) {
            Some(token_type) => token_type,
            None => return lex::IDENTIFIER,
        };

        if let Some(version) = lex::version_rule(token_type) {
            if self.mysql_version < version || -version >= self.mysql_version {
                return lex::IDENTIFIER;
            }
        }

        if token_type == lex::MAX_STATEMENT_TIME_SYMBOL
            && !(self.mysql_version >= 50704 && self.mysql_version < 50708)
        {
            return lex::IDENTIFIER;
        }
        if token_type == lex::NONBLOCKING_SYMBOL
            && !(self.mysql_version >= 50700 && self.mysql_version < 50706)
        {
            return lex::IDENTIFIER;
        }
        if token_type == lex::REMOTE_SYMBOL
            && (self.mysql_version >= 80003 && self.mysql_version < 80014)
        {
            return lex::IDENTIFIER;
        }

        if lex::is_function_token(token_type) {
            if self.is_sql_mode_active(SQL_MODE_IGNORE_SPACE) {
                self.bytes_already_read = span_while(&self.sql, self.bytes_already_read, |byte| {
                    byte_in(byte, lex::WHITESPACE_MASK.as_bytes())
                });
            }
            if self.byte_at(self.bytes_already_read) != Some(b'(') {
                return lex::IDENTIFIER;
            }
        }

        if token_type == lex::NOT_SYMBOL && self.is_sql_mode_active(SQL_MODE_HIGH_NOT_PRECEDENCE) {
            token_type = lex::NOT2_SYMBOL;
        }

        lex::token_synonym(token_type).unwrap_or(token_type)
    }
}

struct Grammar {
    highest_terminal_id: i64,
    rules: Vec<Option<Rule>>,
    query_rule_id: i64,
    select_statement_rule_id: Option<i64>,
}

struct Rule {
    branches: Vec<Vec<i64>>,
    lookahead: Option<Vec<i64>>,
    rule_name: String,
    is_fragment: bool,
}

impl Grammar {
    fn rule(&self, rule_id: i64) -> Option<&Rule> {
        usize::try_from(rule_id)
            .ok()
            .and_then(|index| self.rules.get(index))
            .and_then(Option::as_ref)
    }
}

#[php_class]
#[php(name = "WP_MySQL_Native_Grammar")]
pub struct WpMySqlNativeGrammar {
    grammar: Arc<Grammar>,
}

enum ParserTokenSource {
    Php(Vec<Zval>),
    Native {
        sql_zval: Zval,
        tokens: Vec<TokenInfo>,
        no_backslash: bool,
    },
}

impl ParserTokenSource {
    fn create_php_token_with_classes(&self, index: usize, classes: &PhpClasses) -> PhpResult<Zval> {
        match self {
            Self::Php(tokens) => tokens
                .get(index)
                .map(Zval::shallow_clone)
                .ok_or_else(|| php_error("Parser token index is out of range")),
            Self::Native {
                sql_zval,
                tokens,
                no_backslash,
            } => {
                let token = tokens
                    .get(index)
                    .copied()
                    .ok_or_else(|| php_error("Parser token index is out of range"))?;
                create_mysql_token_with_classes(sql_zval, token, *no_backslash, classes)
            }
        }
    }

    fn token_info(&self, index: usize) -> PhpResult<TokenInfo> {
        match self {
            Self::Php(tokens) => {
                let token = tokens
                    .get(index)
                    .ok_or_else(|| php_error("Parser token index is out of range"))?;
                let token_object = token
                    .object()
                    .ok_or_else(|| php_error("Parser token must be an object"))?;
                let id = token_object.get_property::<i64>("id").map_err(php_error)?;
                let start = token_object
                    .get_property::<i64>("start")
                    .map_err(php_error)?;
                let length = token_object
                    .get_property::<i64>("length")
                    .map_err(php_error)?;
                let start = usize::try_from(start).map_err(php_error)?;
                let length = usize::try_from(length).map_err(php_error)?;

                Ok(TokenInfo {
                    id,
                    start,
                    end: start.saturating_add(length),
                })
            }
            Self::Native { tokens, .. } => tokens
                .get(index)
                .copied()
                .ok_or_else(|| php_error("Parser token index is out of range")),
        }
    }
}

#[derive(Clone, Copy)]
enum NativeAstChild {
    Node(usize),
    Token(usize),
}

#[derive(Clone, Copy)]
enum NativeAstRoot {
    No,
    Empty,
    Node(usize),
    Token(usize),
}

enum NativeParseMatch {
    No,
    Empty,
    Node(usize),
    Token(usize),
    Fragment(Vec<NativeAstChild>),
}

struct NativeAstNode {
    rule_id: i64,
    children: Vec<NativeAstChild>,
    first_token: Option<usize>,
    last_token: Option<usize>,
    descendant_count: usize,
}

struct NativeAstArena {
    grammar: Arc<Grammar>,
    token_source: Arc<ParserTokenSource>,
    nodes: Vec<NativeAstNode>,
    root: NativeAstRoot,
}

#[php_class]
#[php(name = "WP_MySQL_Native_Ast")]
pub struct WpMySqlNativeAst {
    arena: Arc<NativeAstArena>,
}

impl NativeAstArena {
    fn new(grammar: Arc<Grammar>, token_source: Arc<ParserTokenSource>) -> Self {
        Self {
            grammar,
            token_source,
            nodes: Vec::new(),
            root: NativeAstRoot::No,
        }
    }

    fn push_node(&mut self, rule_id: i64, children: Vec<NativeAstChild>) -> usize {
        let index = self.nodes.len();
        let mut first_token = None;
        let mut last_token = None;
        let mut descendant_count = 0;
        for child in &children {
            match child {
                NativeAstChild::Node(child_index) => {
                    if let Some(node) = self.nodes.get(*child_index) {
                        descendant_count += 1 + node.descendant_count;
                        if first_token.is_none() {
                            first_token = node.first_token;
                        }
                        if node.last_token.is_some() {
                            last_token = node.last_token;
                        }
                    }
                }
                NativeAstChild::Token(token_index) => {
                    if first_token.is_none() {
                        first_token = Some(*token_index);
                    }
                    last_token = Some(*token_index);
                    descendant_count += 1;
                }
            }
        }

        self.nodes.push(NativeAstNode {
            rule_id,
            children,
            first_token,
            last_token,
            descendant_count,
        });
        index
    }

    fn create_php_ast(&self, native_ast_zval: &Zval) -> PhpResult<Zval> {
        let classes = php_classes()?;
        self.create_php_ast_with_classes(native_ast_zval, &classes)
    }

    fn create_php_ast_with_classes(
        &self,
        native_ast_zval: &Zval,
        classes: &PhpClasses,
    ) -> PhpResult<Zval> {
        match self.root {
            NativeAstRoot::No => Ok(Zval::null()),
            NativeAstRoot::Empty => {
                let mut zval = Zval::new();
                zval.set_bool(true);
                Ok(zval)
            }
            NativeAstRoot::Node(index) => {
                self.create_php_node_with_classes(native_ast_zval, index, classes)
            }
            NativeAstRoot::Token(index) => self
                .token_source
                .create_php_token_with_classes(index, classes),
        }
    }

    fn create_php_node_with_classes(
        &self,
        native_ast_zval: &Zval,
        index: usize,
        classes: &PhpClasses,
    ) -> PhpResult<Zval> {
        let node = self.node(index)?;
        let mut object = classes.native_parser_node.new();
        let rule_name = self
            .grammar
            .rule(node.rule_id)
            .map(|rule| rule.rule_name.as_str())
            .unwrap_or_default();
        let index = i64::try_from(index).map_err(php_error)?;

        update_object_property(
            &mut object,
            classes.native_parser_node,
            "rule_id",
            node.rule_id,
        )?;
        update_object_property(
            &mut object,
            classes.native_parser_node,
            "rule_name",
            rule_name.to_owned(),
        )?;
        update_object_property(
            &mut object,
            classes.native_parser_node,
            "native_ast",
            native_ast_zval.shallow_clone(),
        )?;
        update_object_property(
            &mut object,
            classes.native_parser_node,
            "native_node_index",
            index,
        )?;

        object.into_zval(false).map_err(php_error)
    }

    fn node(&self, index: usize) -> PhpResult<&NativeAstNode> {
        self.nodes
            .get(index)
            .ok_or_else(|| php_error("Native AST node index is out of range"))
    }

    fn child_to_zval_with_classes(
        &self,
        native_ast_zval: &Zval,
        child: NativeAstChild,
        classes: &PhpClasses,
    ) -> PhpResult<Zval> {
        match child {
            NativeAstChild::Node(index) => {
                self.create_php_node_with_classes(native_ast_zval, index, classes)
            }
            NativeAstChild::Token(index) => self
                .token_source
                .create_php_token_with_classes(index, classes),
        }
    }

    fn child_node_matches(&self, child: NativeAstChild, rule_name: Option<&str>) -> bool {
        let NativeAstChild::Node(index) = child else {
            return false;
        };
        let Ok(node) = self.node(index) else {
            return false;
        };
        rule_name.is_none_or(|expected| {
            self.grammar
                .rule(node.rule_id)
                .map(|rule| rule.rule_name == expected)
                .unwrap_or(false)
        })
    }

    fn child_token_matches(&self, child: NativeAstChild, token_id: Option<i64>) -> bool {
        let NativeAstChild::Token(index) = child else {
            return false;
        };
        token_id.is_none_or(|expected| {
            self.token_source
                .token_info(index)
                .map(|token| token.id == expected)
                .unwrap_or(false)
        })
    }

    fn descendant_stack(&self, index: usize) -> PhpResult<Vec<NativeAstChild>> {
        let node = self.node(index)?;
        let mut stack = Vec::with_capacity(node.descendant_count);
        stack.extend(node.children.iter().rev().copied());
        Ok(stack)
    }
}

fn native_ast(native_ast: &Zval) -> PhpResult<&WpMySqlNativeAst> {
    <&WpMySqlNativeAst as FromZval>::from_zval(native_ast)
        .ok_or_else(|| php_error("Missing native AST handle"))
}

fn native_ast_node_index(node_index: i64) -> PhpResult<usize> {
    usize::try_from(node_index).map_err(php_error)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<bool> {
    let ast = native_ast(native_ast_zval)?;
    Ok(!ast
        .arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .is_empty())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child_node(
    native_ast_zval: &Zval,
    node_index: i64,
    rule_name: Option<String>,
) -> PhpResult<bool> {
    let ast = native_ast(native_ast_zval)?;
    Ok(ast
        .arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .iter()
        .copied()
        .any(|child| ast.arena.child_node_matches(child, rule_name.as_deref())))
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_has_child_token(
    native_ast_zval: &Zval,
    node_index: i64,
    token_id: Option<i64>,
) -> PhpResult<bool> {
    let ast = native_ast(native_ast_zval)?;
    Ok(ast
        .arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .iter()
        .copied()
        .any(|child| ast.arena.child_token_matches(child, token_id)))
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<Zval> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let Some(child) = ast
        .arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .first()
        .copied()
    else {
        return Ok(Zval::null());
    };
    ast.arena
        .child_to_zval_with_classes(native_ast_zval, child, &classes)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child_node(
    native_ast_zval: &Zval,
    node_index: i64,
    rule_name: Option<String>,
) -> PhpResult<Zval> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    for child in &ast.arena.node(native_ast_node_index(node_index)?)?.children {
        if ast.arena.child_node_matches(*child, rule_name.as_deref()) {
            return ast
                .arena
                .child_to_zval_with_classes(native_ast_zval, *child, &classes);
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_child_token(
    native_ast_zval: &Zval,
    node_index: i64,
    token_id: Option<i64>,
) -> PhpResult<Zval> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    for child in &ast.arena.node(native_ast_node_index(node_index)?)?.children {
        if ast.arena.child_token_matches(*child, token_id) {
            return ast
                .arena
                .child_to_zval_with_classes(native_ast_zval, *child, &classes);
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_descendant_node(
    native_ast_zval: &Zval,
    node_index: i64,
    rule_name: Option<String>,
) -> PhpResult<Zval> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let mut stack = ast
        .arena
        .descendant_stack(native_ast_node_index(node_index)?)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_node_matches(child, rule_name.as_deref()) {
            return ast
                .arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_first_descendant_token(
    native_ast_zval: &Zval,
    node_index: i64,
    token_id: Option<i64>,
) -> PhpResult<Zval> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let mut stack = ast
        .arena
        .descendant_stack(native_ast_node_index(node_index)?)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_token_matches(child, token_id) {
            return ast
                .arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(Zval::null())
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_children(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .iter()
        .copied()
        .map(|child| {
            ast.arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes)
        })
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_child_nodes(
    native_ast_zval: &Zval,
    node_index: i64,
    rule_name: Option<String>,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .iter()
        .copied()
        .filter(|child| ast.arena.child_node_matches(*child, rule_name.as_deref()))
        .map(|child| {
            ast.arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes)
        })
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_child_tokens(
    native_ast_zval: &Zval,
    node_index: i64,
    token_id: Option<i64>,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    ast.arena
        .node(native_ast_node_index(node_index)?)?
        .children
        .iter()
        .copied()
        .filter(|child| ast.arena.child_token_matches(*child, token_id))
        .map(|child| {
            ast.arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes)
        })
        .collect()
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendants(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let root = ast.arena.node(native_ast_node_index(node_index)?)?;
    let mut descendants = Vec::with_capacity(root.descendant_count);
    let mut stack = ast
        .arena
        .descendant_stack(native_ast_node_index(node_index)?)?;
    while let Some(child) = stack.pop() {
        descendants.push(
            ast.arena
                .child_to_zval_with_classes(native_ast_zval, child, &classes)?,
        );
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendant_nodes(
    native_ast_zval: &Zval,
    node_index: i64,
    rule_name: Option<String>,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let mut descendants = Vec::new();
    let mut stack = ast
        .arena
        .descendant_stack(native_ast_node_index(node_index)?)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_node_matches(child, rule_name.as_deref()) {
            descendants.push(ast.arena.child_to_zval_with_classes(
                native_ast_zval,
                child,
                &classes,
            )?);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_descendant_tokens(
    native_ast_zval: &Zval,
    node_index: i64,
    token_id: Option<i64>,
) -> PhpResult<Vec<Zval>> {
    let ast = native_ast(native_ast_zval)?;
    let classes = php_classes()?;
    let mut descendants = Vec::new();
    let mut stack = ast
        .arena
        .descendant_stack(native_ast_node_index(node_index)?)?;
    while let Some(child) = stack.pop() {
        if ast.arena.child_token_matches(child, token_id) {
            descendants.push(ast.arena.child_to_zval_with_classes(
                native_ast_zval,
                child,
                &classes,
            )?);
        }
        if let NativeAstChild::Node(index) = child {
            for child in ast.arena.node(index)?.children.iter().rev() {
                stack.push(*child);
            }
        }
    }
    Ok(descendants)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_start(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<i64> {
    let ast = native_ast(native_ast_zval)?;
    let node = ast.arena.node(native_ast_node_index(node_index)?)?;
    let token_index = node
        .first_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let token = ast.arena.token_source.token_info(token_index)?;
    i64::try_from(token.start).map_err(php_error)
}

#[php_function]
pub fn wp_sqlite_mysql_native_ast_get_length(
    native_ast_zval: &Zval,
    node_index: i64,
) -> PhpResult<i64> {
    let ast = native_ast(native_ast_zval)?;
    let node = ast.arena.node(native_ast_node_index(node_index)?)?;
    let first_token_index = node
        .first_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let last_token_index = node
        .last_token
        .ok_or_else(|| php_error("Native AST node has no descendant tokens"))?;
    let first_token = ast.arena.token_source.token_info(first_token_index)?;
    let last_token = ast.arena.token_source.token_info(last_token_index)?;
    let length = last_token.end.saturating_sub(first_token.start);
    i64::try_from(length).map_err(php_error)
}

#[php_class]
#[php(name = "WP_MySQL_Native_Parser")]
pub struct WpMySqlNativeParser {
    grammar: Arc<Grammar>,
    token_source: Arc<ParserTokenSource>,
    token_ids: Vec<i64>,
    position: usize,
    current_ast: Option<Arc<NativeAstArena>>,
    current_php_ast: Option<Zval>,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpMySqlNativeParser {
    pub fn __construct(grammar: &mut Zval, tokens: &mut Zval) -> PhpResult<Self> {
        let grammar = export_grammar(grammar)?;
        let (token_source, token_ids) = export_tokens(tokens)?;

        Ok(Self {
            grammar,
            token_source: Arc::new(token_source),
            token_ids,
            position: 0,
            current_ast: None,
            current_php_ast: None,
        })
    }

    pub fn reset_tokens(&mut self, tokens: &mut Zval) -> PhpResult<()> {
        let (token_source, token_ids) = export_tokens(tokens)?;

        self.token_source = Arc::new(token_source);
        self.token_ids = token_ids;
        self.position = 0;
        self.current_ast = None;
        self.current_php_ast = None;

        Ok(())
    }

    pub fn parse(&mut self) -> PhpResult<Zval> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            let ast = self.parse_native_ast()?;
            self.create_php_ast(ast)
        })
    }

    pub fn next_query(&mut self) -> PhpResult<bool> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || self.next_query_inner())
    }

    pub fn get_query_ast(&mut self) -> PhpResult<Zval> {
        if let Some(ast) = self.current_php_ast.as_ref() {
            return Ok(ast.shallow_clone());
        }

        let Some(native_ast) = self.current_ast.as_ref() else {
            return Ok(Zval::null());
        };

        let ast = self.create_php_ast(Arc::clone(native_ast))?;
        self.current_php_ast = Some(ast);
        match self.current_php_ast.as_ref() {
            Some(ast) => Ok(ast.shallow_clone()),
            None => Ok(Zval::null()),
        }
    }
}

impl WpMySqlNativeParser {
    fn next_query_inner(&mut self) -> PhpResult<bool> {
        if self.position >= self.token_ids.len() {
            self.current_ast = None;
            self.current_php_ast = None;
            return Ok(false);
        }

        self.current_ast = Some(self.parse_native_ast()?);
        self.current_php_ast = None;
        Ok(true)
    }

    fn parse_native_ast(&mut self) -> PhpResult<Arc<NativeAstArena>> {
        let mut arena =
            NativeAstArena::new(Arc::clone(&self.grammar), Arc::clone(&self.token_source));
        let query_rule_id = self.grammar.query_rule_id;
        arena.root = match self.parse_recursive_inner(&mut arena, query_rule_id)? {
            NativeParseMatch::No => NativeAstRoot::No,
            NativeParseMatch::Empty => NativeAstRoot::Empty,
            NativeParseMatch::Node(index) => NativeAstRoot::Node(index),
            NativeParseMatch::Token(index) => NativeAstRoot::Token(index),
            NativeParseMatch::Fragment(children) => {
                if children.is_empty() {
                    NativeAstRoot::Empty
                } else {
                    NativeAstRoot::Node(arena.push_node(query_rule_id, children))
                }
            }
        };
        Ok(Arc::new(arena))
    }

    fn parse_recursive_inner(
        &mut self,
        arena: &mut NativeAstArena,
        rule_id: i64,
    ) -> PhpResult<NativeParseMatch> {
        if rule_id <= self.grammar.highest_terminal_id {
            if self.position >= self.token_ids.len() {
                return Ok(NativeParseMatch::No);
            }
            if rule_id == 0 {
                return Ok(NativeParseMatch::Empty);
            }
            if self.token_ids[self.position] == rule_id {
                let token_index = self.position;
                self.position += 1;
                return Ok(NativeParseMatch::Token(token_index));
            }
            return Ok(NativeParseMatch::No);
        }

        let grammar = unsafe {
            // The parser owns an Arc to immutable grammar data for its full lifetime.
            // Taking a raw shared reference avoids cloning hot branches just to satisfy
            // the borrow checker while recursive parsing mutates only `position`.
            &*Arc::as_ptr(&self.grammar)
        };

        let Some(rule) = grammar.rule(rule_id) else {
            return Ok(NativeParseMatch::No);
        };
        if rule.branches.is_empty() {
            return Ok(NativeParseMatch::No);
        }

        if let Some(lookahead) = rule.lookahead.as_ref() {
            let token_id = self.token_ids.get(self.position).copied().unwrap_or(0);
            if lookahead.binary_search(&token_id).is_err() && lookahead.binary_search(&0).is_err() {
                return Ok(NativeParseMatch::No);
            }
        }

        let starting_position = self.position;
        let starting_node_count = arena.nodes.len();
        let mut matched_children = None;

        for branch in &rule.branches {
            self.position = starting_position;
            arena.nodes.truncate(starting_node_count);
            let mut children = Vec::new();
            let mut branch_matches = true;

            for &subrule_id in branch {
                match self.parse_recursive_inner(arena, subrule_id)? {
                    NativeParseMatch::No => {
                        branch_matches = false;
                        break;
                    }
                    NativeParseMatch::Empty => {}
                    NativeParseMatch::Token(token_index) => {
                        children.push(NativeAstChild::Token(token_index));
                    }
                    NativeParseMatch::Node(node_index) => {
                        children.push(NativeAstChild::Node(node_index));
                    }
                    NativeParseMatch::Fragment(fragment_children) => {
                        children.extend(fragment_children);
                    }
                }
            }

            if branch_matches
                && grammar.select_statement_rule_id == Some(rule_id)
                && self
                    .token_ids
                    .get(self.position)
                    .is_some_and(|token_id| *token_id == lex::INTO_SYMBOL)
            {
                branch_matches = false;
            }

            if branch_matches {
                matched_children = Some(children);
                break;
            }
        }

        let Some(children) = matched_children else {
            self.position = starting_position;
            arena.nodes.truncate(starting_node_count);
            return Ok(NativeParseMatch::No);
        };

        if children.is_empty() {
            Ok(NativeParseMatch::Empty)
        } else if rule.is_fragment {
            Ok(NativeParseMatch::Fragment(children))
        } else {
            Ok(NativeParseMatch::Node(arena.push_node(rule_id, children)))
        }
    }

    fn create_php_ast(&self, arena: Arc<NativeAstArena>) -> PhpResult<Zval> {
        stacker::maybe_grow(STACK_RED_ZONE, STACK_GROW_SIZE, || {
            let native_ast_zval = WpMySqlNativeAst { arena }
                .into_zval(false)
                .map_err(php_error)?;
            let native_ast = native_ast(&native_ast_zval)?;
            native_ast.arena.create_php_ast(&native_ast_zval)
        })
    }
}

fn export_grammar(grammar_zval: &mut Zval) -> PhpResult<Arc<Grammar>> {
    if let Some(cached) = cached_native_grammar(grammar_zval)? {
        return Ok(cached);
    }

    let exported = php_function("wp_sqlite_mysql_native_export_grammar")?
        .try_call(vec![&*grammar_zval as &dyn IntoZvalDyn])
        .map_err(php_error)?;
    let array = exported
        .array()
        .ok_or_else(|| php_error("Exported grammar must be an array"))?;

    let highest_terminal_id = array
        .get("highest_terminal_id")
        .and_then(Zval::long)
        .ok_or_else(|| php_error("Missing grammar highest_terminal_id"))?;
    let parsed_rules = parse_rules(
        array
            .get("rules")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar rules"))?,
    )?;
    let parsed_lookahead = parse_lookahead(
        array
            .get("lookahead_is_match_possible")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar lookahead"))?,
    )?;
    let parsed_rule_names = parse_rule_names(
        array
            .get("rule_names")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar rule_names"))?,
    )?;
    let parsed_fragment_ids = parse_id_set(
        array
            .get("fragment_ids")
            .and_then(Zval::array)
            .ok_or_else(|| php_error("Missing grammar fragment_ids"))?,
    )?;
    let query_rule_id = parsed_rule_names
        .iter()
        .find_map(|(id, name)| (name == "query").then_some(*id))
        .ok_or_else(|| php_error("Missing query grammar rule"))?;
    let select_statement_rule_id = parsed_rule_names
        .iter()
        .find_map(|(id, name)| (name == "selectStatement").then_some(*id));
    let rules = build_rules(
        parsed_rules,
        parsed_lookahead,
        parsed_rule_names,
        parsed_fragment_ids,
    )?;

    let grammar = Arc::new(Grammar {
        highest_terminal_id,
        rules,
        query_rule_id,
        select_statement_rule_id,
    });

    cache_native_grammar(grammar_zval, Arc::clone(&grammar))?;

    Ok(grammar)
}

fn cached_native_grammar(grammar: &Zval) -> PhpResult<Option<Arc<Grammar>>> {
    let object = grammar
        .object()
        .ok_or_else(|| php_error("Parser grammar must be an object"))?;
    let properties = object.get_properties().map_err(php_error)?;
    let Some(native_grammar) = properties.get("native_grammar") else {
        return Ok(None);
    };
    let Some(native_grammar) = <&WpMySqlNativeGrammar as FromZval>::from_zval(native_grammar)
    else {
        return Ok(None);
    };

    Ok(Some(Arc::clone(&native_grammar.grammar)))
}

fn cache_native_grammar(grammar_zval: &mut Zval, grammar: Arc<Grammar>) -> PhpResult<()> {
    let object = grammar_zval
        .object_mut()
        .ok_or_else(|| php_error("Parser grammar must be an object"))?;
    let native_grammar = WpMySqlNativeGrammar { grammar }
        .into_zval(false)
        .map_err(php_error)?;
    object
        .set_property("native_grammar", native_grammar)
        .map_err(php_error)
}

fn export_tokens(tokens: &mut Zval) -> PhpResult<(ParserTokenSource, Vec<i64>)> {
    if let Some(stream) = <&WpMySqlNativeTokenStream as FromZval>::from_zval(tokens) {
        let token_ids = stream.tokens.iter().map(|token| token.id).collect();
        return Ok((
            ParserTokenSource::Native {
                sql_zval: stream.sql_zval.shallow_clone(),
                tokens: stream.tokens.clone(),
                no_backslash: stream.no_backslash,
            },
            token_ids,
        ));
    }

    let array = tokens
        .array()
        .ok_or_else(|| php_error("Parser tokens must be an array"))?;
    let mut token_objects = Vec::with_capacity(array.len());
    let mut token_ids = Vec::with_capacity(array.len());

    for (_, token) in array {
        let token_object = token
            .object()
            .ok_or_else(|| php_error("Parser token must be an object"))?;
        let id = token_object.get_property::<i64>("id").map_err(php_error)?;
        token_objects.push(token.shallow_clone());
        token_ids.push(id);
    }

    Ok((ParserTokenSource::Php(token_objects), token_ids))
}

fn build_rules(
    rules: HashMap<i64, Vec<Vec<i64>>>,
    lookahead: HashMap<i64, HashSet<i64>>,
    rule_names: HashMap<i64, String>,
    fragment_ids: HashSet<i64>,
) -> PhpResult<Vec<Option<Rule>>> {
    let max_rule_id = rules
        .keys()
        .chain(lookahead.keys())
        .chain(rule_names.keys())
        .chain(fragment_ids.iter())
        .copied()
        .max()
        .unwrap_or(0);
    let max_rule_index = usize::try_from(max_rule_id).map_err(php_error)?;
    let mut dense_rules: Vec<Option<Rule>> = (0..=max_rule_index).map(|_| None).collect();

    for (rule_id, branches) in rules {
        let index = usize::try_from(rule_id).map_err(php_error)?;
        let mut lookahead = lookahead.get(&rule_id).map(|set| {
            let mut values: Vec<i64> = set.iter().copied().collect();
            values.sort_unstable();
            values
        });
        if let Some(values) = lookahead.as_mut() {
            values.dedup();
        }

        dense_rules[index] = Some(Rule {
            branches,
            lookahead,
            rule_name: rule_names.get(&rule_id).cloned().unwrap_or_default(),
            is_fragment: fragment_ids.contains(&rule_id),
        });
    }

    Ok(dense_rules)
}

fn parse_rules(array: &ZendHashTable) -> PhpResult<HashMap<i64, Vec<Vec<i64>>>> {
    let mut rules = HashMap::new();
    for (rule_key, branches_zval) in array {
        let rule_id = array_key_to_i64(rule_key)?;
        let branches_array = branches_zval
            .array()
            .ok_or_else(|| php_error("Grammar branches must be arrays"))?;
        let mut branches = Vec::with_capacity(branches_array.len());
        for (_, branch_zval) in branches_array {
            let branch_array = branch_zval
                .array()
                .ok_or_else(|| php_error("Grammar branch must be an array"))?;
            let mut branch = Vec::with_capacity(branch_array.len());
            for (_, subrule_zval) in branch_array {
                branch.push(
                    subrule_zval
                        .long()
                        .ok_or_else(|| php_error("Grammar subrule must be an integer"))?,
                );
            }
            branches.push(branch);
        }
        rules.insert(rule_id, branches);
    }
    Ok(rules)
}

fn parse_lookahead(array: &ZendHashTable) -> PhpResult<HashMap<i64, HashSet<i64>>> {
    let mut lookahead = HashMap::new();
    for (rule_key, lookup_zval) in array {
        let rule_id = array_key_to_i64(rule_key)?;
        let lookup_array = lookup_zval
            .array()
            .ok_or_else(|| php_error("Grammar lookahead entry must be an array"))?;
        let mut set = HashSet::with_capacity(lookup_array.len());
        for (token_key, _) in lookup_array {
            set.insert(array_key_to_i64(token_key)?);
        }
        lookahead.insert(rule_id, set);
    }
    Ok(lookahead)
}

fn parse_rule_names(array: &ZendHashTable) -> PhpResult<HashMap<i64, String>> {
    let mut names = HashMap::new();
    for (rule_key, name_zval) in array {
        names.insert(
            array_key_to_i64(rule_key)?,
            name_zval
                .string()
                .ok_or_else(|| php_error("Grammar rule name must be a string"))?,
        );
    }
    Ok(names)
}

fn parse_id_set(array: &ZendHashTable) -> PhpResult<HashSet<i64>> {
    let mut set = HashSet::with_capacity(array.len());
    for (key, _) in array {
        set.insert(array_key_to_i64(key)?);
    }
    Ok(set)
}

fn array_key_to_i64(key: ArrayKey<'_>) -> PhpResult<i64> {
    match key {
        ArrayKey::Long(value) => Ok(value),
        ArrayKey::String(value) => value.parse::<i64>().map_err(php_error),
        ArrayKey::Str(value) => value.parse::<i64>().map_err(php_error),
    }
}

extern "C" fn php_module_info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_row!("wp_mysql_parser", "enabled");
    info_table_end!();
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<WpMySqlNativeGrammar>()
        .class::<WpMySqlNativeAst>()
        .class::<WpMySqlNativeTokenStream>()
        .class::<WpMySqlNativeLexer>()
        .class::<WpMySqlNativeParser>()
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child_node))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_has_child_token))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_first_child))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_child_node
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_child_token
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_descendant_node
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_first_descendant_token
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_children))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_child_nodes))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_child_tokens))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_descendants))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_descendant_nodes
        ))
        .function(wrap_function!(
            wp_sqlite_mysql_native_ast_get_descendant_tokens
        ))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_start))
        .function(wrap_function!(wp_sqlite_mysql_native_ast_get_length))
        .info_function(php_module_info)
}
