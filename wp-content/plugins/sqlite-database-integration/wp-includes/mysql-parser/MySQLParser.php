<?php

class ASTNode
{
    public $type;
    public $value;
    public $children;

    public function __construct($type, $value_or_children=null, $children = [])
    {
        $this->type = $type;
        if(is_array($value_or_children)) {
            $this->children = $value_or_children;
        } else {
            $this->children = $children;
            $this->value = $value_or_children;
        }
    }

    static public function fromToken(MySQLToken $token)
    {
        return new ASTNode(
            MySQLLexer::getTokenName($token->getType()),
            $token->getText()
        );
    }

    public function __tostring()
    {
        return 'Token<' . $this->type . ($this->value ? ', ' . $this->value : '') . '>';
    }
}


class MySQLParser {
    private $lexer;
    private $serverVersion;

    public function __construct($lexer)
    {
        $this->lexer = $lexer;
        $this->serverVersion = $lexer->getServerVersion();
    }
    
    public function query()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::EOF || $token->getType() === MySQLLexer::SEMICOLON_SYMBOL) {
            return new ASTNode('query', [new ASTNode('EOF')]);
        }
        $children = [];

        while ($this->isSimpleStatementStart($this->lexer->peekNextToken())) {
            $children[] = $this->simpleStatement();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SEMICOLON_SYMBOL) {
                $this->match(MySQLLexer::SEMICOLON_SYMBOL);
            }
        }

        if ($token->getType() === MySQLLexer::BEGIN_SYMBOL) {
            $children[] = $this->beginWork();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SEMICOLON_SYMBOL) {
                $this->match(MySQLLexer::SEMICOLON_SYMBOL);
            }
        }

        if (
            $this->lexer->peekNextToken()->getType() !== MySQLLexer::EOF &&
            $this->lexer->peekNextToken()->getType() !== MySQLLexer::SEMICOLON_SYMBOL
        ) {
            throw new \Exception('Unexpected token: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('query', $children);
    }

    public function simpleStatement()
    {
        $token = $this->lexer->peekNextToken();

        switch ($token->getType()) {
            // DDL
            case MySQLLexer::ALTER_SYMBOL:
                return $this->alterStatement();
            case MySQLLexer::CREATE_SYMBOL:
                return $this->createStatement();
            case MySQLLexer::DROP_SYMBOL:
                return $this->dropStatement();
            case MySQLLexer::RENAME_SYMBOL:
                return $this->renameTableStatement();
            case MySQLLexer::TRUNCATE_SYMBOL:
                return $this->truncateTableStatement();
            case MySQLLexer::IMPORT_SYMBOL:
                if ($this->serverVersion >= 80000) {
                    return $this->importStatement();
                }
                break;

            // DML
            case MySQLLexer::CALL_SYMBOL:
                return $this->callStatement();
            case MySQLLexer::DELETE_SYMBOL:
                return $this->deleteStatement();
            case MySQLLexer::DO_SYMBOL:
                return $this->doStatement();
            case MySQLLexer::HANDLER_SYMBOL:
                return $this->handlerStatement();
            case MySQLLexer::INSERT_SYMBOL:
                return $this->insertStatement();
            case MySQLLexer::LOAD_SYMBOL:
                return $this->loadStatement();
            case MySQLLexer::REPLACE_SYMBOL:
                return $this->replaceStatement();
            case MySQLLexer::WITH_SYMBOL:
            case MySQLLexer::SELECT_SYMBOL:
                return $this->selectStatement();
            case MySQLLexer::UPDATE_SYMBOL:
                return $this->updateStatement();
            case MySQLLexer::START_SYMBOL:
            case MySQLLexer::COMMIT_SYMBOL:
            case MySQLLexer::SAVEPOINT_SYMBOL:
            case MySQLLexer::ROLLBACK_SYMBOL:
            case MySQLLexer::RELEASE_SYMBOL:
            case MySQLLexer::LOCK_SYMBOL:
            case MySQLLexer::UNLOCK_SYMBOL:
            case MySQLLexer::XA_SYMBOL:
                return $this->transactionOrLockingStatement();
            case MySQLLexer::PURGE_SYMBOL:
            case MySQLLexer::CHANGE_SYMBOL:
            case MySQLLexer::RESET_SYMBOL:
                return $this->replicationStatement();
            case MySQLLexer::PREPARE_SYMBOL:
            case MySQLLexer::EXECUTE_SYMBOL:
            case MySQLLexer::DEALLOCATE_SYMBOL:
                return $this->preparedStatement();

            // Data Directory
            case MySQLLexer::CLONE_SYMBOL:
                if ($this->serverVersion >= 80000) {
                    return $this->cloneStatement();
                }
                break;

            // Database administration
            case MySQLLexer::CREATE_SYMBOL:
            case MySQLLexer::DROP_SYMBOL:
            case MySQLLexer::GRANT_SYMBOL:
            case MySQLLexer::RENAME_SYMBOL:
            case MySQLLexer::REVOKE_SYMBOL:
                return $this->accountManagementStatement();
            case MySQLLexer::ANALYZE_SYMBOL:
            case MySQLLexer::CHECK_SYMBOL:
            case MySQLLexer::CHECKSUM_SYMBOL:
            case MySQLLexer::OPTIMIZE_SYMBOL:
            case MySQLLexer::REPAIR_SYMBOL:
                return $this->tableAdministrationStatement();
            case MySQLLexer::SET_SYMBOL:
                return $this->setStatement();
            case MySQLLexer::SHOW_SYMBOL:
                return $this->showStatement();
            case MySQLLexer::BINLOG_SYMBOL:
            case MySQLLexer::CACHE_SYMBOL:
            case MySQLLexer::FLUSH_SYMBOL:
            case MySQLLexer::KILL_SYMBOL:
            case MySQLLexer::LOAD_SYMBOL:
                return $this->otherAdministrativeStatement();

            case MySQLLexer::SHUTDOWN_SYMBOL:
                if ($this->serverVersion >= 50709) {
                    return $this->otherAdministrativeStatement();
                }
                break;

            case MySQLLexer::ALTER_SYMBOL:
                if ($this->serverVersion >= 50606) {
                    return $this->accountManagementStatement();
                }
                break;

            // Resource groups
            case MySQLLexer::CREATE_SYMBOL:
            case MySQLLexer::ALTER_SYMBOL:
            case MySQLLexer::SET_SYMBOL:
            case MySQLLexer::DROP_SYMBOL:
                if ($this->serverVersion >= 80000 &&
                    $this->lexer->peekNextToken(2)->getType() === MySQLLexer::RESOURCE_SYMBOL) {
                    return $this->resourceGroupManagement();
                }
                break;

            // MySQL utility statements
            case MySQLLexer::EXPLAIN_SYMBOL:
            case MySQLLexer::DESCRIBE_SYMBOL:
            case MySQLLexer::DESC_SYMBOL:
            case MySQLLexer::HELP_SYMBOL:
            case MySQLLexer::USE_SYMBOL:
                return $this->utilityStatement();

            case MySQLLexer::RESTART_SYMBOL:
                if ($this->serverVersion >= 80011) {
                    return $this->utilityStatement();
                }
                break;

            case MySQLLexer::GET_SYMBOL:
                if ($this->serverVersion >= 50604) {
                    return $this->getDiagnostics();
                }
                break;
            case MySQLLexer::SIGNAL_SYMBOL:
                return $this->signalStatement();
            case MySQLLexer::RESIGNAL_SYMBOL:
                return $this->resignalStatement();

            // Plugin
            case MySQLLexer::INSTALL_SYMBOL:
            case MySQLLexer::UNINSTALL_SYMBOL:
                return $this->installUninstallStatment();
        }

        throw new \Exception("Unknown simple statement starting with: " . $token->getText());
    }

    private function isSimpleStatementStart($token)
    {
        return $this->isDdlStatementStart($token) ||
               $this->isDmlStatementStart($token) ||
               $this->isAccountManagementStatementStart($token) ||
               $this->isTableAdministrationStatementStart($token) ||
               $this->isUtilityStatementStart($token) ||
               $token->getType() === MySQLLexer::SET_SYMBOL ||
               $token->getType() === MySQLLexer::SHOW_SYMBOL ||
               ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::CLONE_SYMBOL) ||
               $this->isOtherAdministrativeStatementStart($token) ||
               ($this->serverVersion >= 80000 &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::RESOURCE_SYMBOL) ||
               $token->getType() === MySQLLexer::INSTALL_SYMBOL ||
               $token->getType() === MySQLLexer::UNINSTALL_SYMBOL;
    }

    private function isDdlStatementStart($token)
    {
        return $token->getType() === MySQLLexer::ALTER_SYMBOL ||
               $token->getType() === MySQLLexer::CREATE_SYMBOL ||
               $token->getType() === MySQLLexer::DROP_SYMBOL ||
               $token->getType() === MySQLLexer::RENAME_SYMBOL ||
               $token->getType() === MySQLLexer::TRUNCATE_SYMBOL ||
               ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::IMPORT_SYMBOL);
    }

    private function isDmlStatementStart($token)
    {
        return $token->getType() === MySQLLexer::CALL_SYMBOL ||
               $token->getType() === MySQLLexer::DELETE_SYMBOL ||
               $token->getType() === MySQLLexer::DO_SYMBOL ||
               $token->getType() === MySQLLexer::HANDLER_SYMBOL ||
               $token->getType() === MySQLLexer::INSERT_SYMBOL ||
               $token->getType() === MySQLLexer::LOAD_SYMBOL ||
               $token->getType() === MySQLLexer::REPLACE_SYMBOL ||
               $token->getType() === MySQLLexer::WITH_SYMBOL ||
               $token->getType() === MySQLLexer::SELECT_SYMBOL ||
               $token->getType() === MySQLLexer::UPDATE_SYMBOL ||
               $this->isTransactionOrLockingStatementStart($token) ||
               $this->isReplicationStatementStart($token) ||
               $this->isPreparedStatementStart($token);
    }

    private function isAccountManagementStatementStart($token)
    {
        return $token->getType() === MySQLLexer::CREATE_SYMBOL ||
               $token->getType() === MySQLLexer::DROP_SYMBOL ||
               $token->getType() === MySQLLexer::GRANT_SYMBOL ||
               $token->getType() === MySQLLexer::RENAME_SYMBOL ||
               $token->getType() === MySQLLexer::REVOKE_SYMBOL ||
               ($this->serverVersion >= 50606 && $token->getType() === MySQLLexer::ALTER_SYMBOL) ||
               ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::SET_SYMBOL);
    }

    private function isTableAdministrationStatementStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::ANALYZE_SYMBOL:
        case MySQLLexer::CHECK_SYMBOL:
        case MySQLLexer::CHECKSUM_SYMBOL:
        case MySQLLexer::OPTIMIZE_SYMBOL:
        case MySQLLexer::REPAIR_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isUtilityStatementStart($token)
    {
        return $token->getType() === MySQLLexer::EXPLAIN_SYMBOL ||
               $token->getType() === MySQLLexer::DESCRIBE_SYMBOL ||
               $token->getType() === MySQLLexer::DESC_SYMBOL ||
               $token->getType() === MySQLLexer::HELP_SYMBOL ||
               $token->getType() === MySQLLexer::USE_SYMBOL ||
               ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::RESTART_SYMBOL);
    }

    private function isTransactionOrLockingStatementStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::START_SYMBOL:
        case MySQLLexer::COMMIT_SYMBOL:
        case MySQLLexer::SAVEPOINT_SYMBOL:
        case MySQLLexer::ROLLBACK_SYMBOL:
        case MySQLLexer::RELEASE_SYMBOL:
        case MySQLLexer::LOCK_SYMBOL:
        case MySQLLexer::UNLOCK_SYMBOL:
        case MySQLLexer::XA_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isReplicationStatementStart($token)
    {
        return $token->getType() === MySQLLexer::PURGE_SYMBOL ||
               $token->getType() === MySQLLexer::CHANGE_SYMBOL ||
               $token->getType() === MySQLLexer::RESET_SYMBOL ||
               $token->getType() === MySQLLexer::START_SYMBOL ||
               $token->getType() === MySQLLexer::STOP_SYMBOL ||
               ($this->serverVersion >= 50700 && $token->getType() === MySQLLexer::LOAD_SYMBOL);
    }

    private function isPreparedStatementStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::PREPARE_SYMBOL:
        case MySQLLexer::EXECUTE_SYMBOL:
        case MySQLLexer::DEALLOCATE_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isOtherAdministrativeStatementStart($token)
    {
        return $token->getType() === MySQLLexer::BINLOG_SYMBOL ||
               $token->getType() === MySQLLexer::CACHE_SYMBOL ||
               $token->getType() === MySQLLexer::FLUSH_SYMBOL ||
               $token->getType() === MySQLLexer::KILL_SYMBOL ||
               $token->getType() === MySQLLexer::LOAD_SYMBOL ||
               ($this->serverVersion >= 50709 && $token->getType() === MySQLLexer::SHUTDOWN_SYMBOL);
    }

    //----------------- DDL statements -------------------------------------------------------------------------------------

    public function alterStatement()
    {
        $this->match(MySQLLexer::ALTER_SYMBOL);
        $token = $this->lexer->peekNextToken();
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ALTER_SYMBOL))];

        if ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->alterTable();
        } elseif ($token->getType() === MySQLLexer::DATABASE_SYMBOL) {
            $children[] = $this->alterDatabase();
        } elseif ($token->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
            $children[] = $this->procedureRef();
            $nextToken = $this->lexer->peekNextToken();
            if ($nextToken->getType() === MySQLLexer::COMMENT_SYMBOL ||
                $nextToken->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
                $nextToken->getType() === MySQLLexer::NO_SYMBOL ||
                $nextToken->getType() === MySQLLexer::CONTAINS_SYMBOL ||
                $nextToken->getType() === MySQLLexer::READS_SYMBOL ||
                $nextToken->getType() === MySQLLexer::MODIFIES_SYMBOL ||
                $nextToken->getType() === MySQLLexer::SQL_SYMBOL) {
                $children[] = $this->routineAlterOptions();
            }
        } elseif ($token->getType() === MySQLLexer::FUNCTION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
            $children[] = $this->functionRef();
            $nextToken = $this->lexer->peekNextToken();
            if ($nextToken->getType() === MySQLLexer::COMMENT_SYMBOL ||
                $nextToken->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
                $nextToken->getType() === MySQLLexer::NO_SYMBOL ||
                $nextToken->getType() === MySQLLexer::CONTAINS_SYMBOL ||
                $nextToken->getType() === MySQLLexer::READS_SYMBOL ||
                $nextToken->getType() === MySQLLexer::MODIFIES_SYMBOL ||
                $nextToken->getType() === MySQLLexer::SQL_SYMBOL) {
                $children[] = $this->routineAlterOptions();
            }
        } elseif ($token->getType() === MySQLLexer::VIEW_SYMBOL) {
            $children[] = $this->alterView();
        } elseif ($token->getType() === MySQLLexer::EVENT_SYMBOL) {
            $children[] = $this->alterEvent();
        } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
            $children[] = $this->alterTablespace();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::UNDO_SYMBOL) {
            $children[] = $this->alterUndoTablespace();
        } elseif ($token->getType() === MySQLLexer::LOGFILE_SYMBOL) {
            $children[] = $this->alterLogfileGroup();
        } elseif ($this->serverVersion >= 50713 && $token->getType() === MySQLLexer::INSTANCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSTANCE_SYMBOL);
            $children[] = $this->match(MySQLLexer::ROTATE_SYMBOL);
            $children[] = $this->textOrIdentifier();
            $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in alterStatement: ' . $token->getText());
        }

        return new ASTNode('alterStatement', $children);
    }

    public function alterDatabase()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DATABASE_SYMBOL);
        $children[] = $this->schemaRef();
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
            $token->getType() === MySQLLexer::CHARSET_SYMBOL ||
            $token->getType() === MySQLLexer::COLLATE_SYMBOL ||
            ($this->serverVersion >= 80016 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL)) {
            do {
                $children[] = $this->createDatabaseOption();
            } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::CHARSET_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL ||
                     ($this->serverVersion >= 80016 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCRYPTION_SYMBOL));
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::UPGRADE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UPGRADE_SYMBOL);
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);
            $children[] = $this->match(MySQLLexer::NAME_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in alterDatabase: ' . $token->getText());
        }

        return new ASTNode('alterDatabase', $children);
    }

    public function alterEvent()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        $children[] = $this->match(MySQLLexer::EVENT_SYMBOL);
        $children[] = $this->eventRef();

        if ($this->lexer->peekNextToken()->getText() === 'ON SCHEDULE') {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->match(MySQLLexer::SCHEDULE_SYMBOL);
            $children[] = $this->schedule();
        }

        if ($this->lexer->peekNextToken()->getText() === 'ON COMPLETION') {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->match(MySQLLexer::COMPLETION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::PRESERVE_SYMBOL);
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RENAME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RENAME_SYMBOL);
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->identifier();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DISABLE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ENABLE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::DISABLE_SYMBOL);
                if ($this->lexer->peekNextToken()->getText() === 'ON SLAVE') {
                    $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                    $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
                }
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            $children[] = $this->textLiteral();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DO_SYMBOL);
            $children[] = $this->compoundStatement();
        }

        return new ASTNode('alterEvent', $children);
    }

    public function alterLogfileGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOGFILE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->logfileGroupRef();
        $children[] = $this->match(MySQLLexer::ADD_SYMBOL);
        $children[] = $this->match(MySQLLexer::UNDOFILE_SYMBOL);
        $children[] = $this->textLiteral();
        switch ($this->lexer->peekNextToken()->getType()) {
            case MySQLLexer::INITIAL_SIZE_SYMBOL:
            case MySQLLexer::ENGINE_SYMBOL:
            case MySQLLexer::WAIT_SYMBOL:
            case MySQLLexer::NO_WAIT_SYMBOL:
            $children[] = $this->alterLogfileGroupOptions();
            break;
        }

        return new ASTNode('alterLogfileGroup', $children);
    }

    public function alterLogfileGroupOptions()
    {
        $children = [];

        $children[] = $this->alterLogfileGroupOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->alterLogfileGroupOption();
        }

        return new ASTNode('alterLogfileGroupOptions', $children);
    }

    public function alterLogfileGroupOption()
    {
        $token = $this->lexer->peekNextToken();
        switch ($token->getType()) {
            case MySQLLexer::INITIAL_SIZE_SYMBOL:
                return $this->tsOptionInitialSize();
            case MySQLLexer::ENGINE_SYMBOL:
                return $this->tsOptionEngine();
            case MySQLLexer::WAIT_SYMBOL:
            case MySQLLexer::NO_WAIT_SYMBOL:
                return $this->tsOptionWait();
            default:
                throw new \Exception('Unexpected token in alterLogfileGroupOption: ' . $token->getText());
        }
    }

    public function alterServer()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SERVER_SYMBOL);
        $children[] = $this->serverRef();
        $children[] = $this->serverOptions();

        return new ASTNode('alterServer', $children);
    }

    public function alterTable()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ONLINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::OFFLINE_SYMBOL) {
            $children[] = $this->onlineOption();
        }

        if ($this->serverVersion < 50700 && $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        $children[] = $this->tableRef();
        switch ($this->lexer->peekNextToken()->getType()) {
            case MySQLLexer::ADD_SYMBOL:
            case MySQLLexer::ALGORITHM_SYMBOL:
            case MySQLLexer::CHANGE_SYMBOL:
            case MySQLLexer::CONVERT_SYMBOL:
            case MySQLLexer::DISABLE_SYMBOL:
            case MySQLLexer::DISCARD_SYMBOL:
            case MySQLLexer::DROP_SYMBOL:
            case MySQLLexer::ENABLE_SYMBOL:
            case MySQLLexer::FORCE_SYMBOL:
            case MySQLLexer::IMPORT_SYMBOL:
            case MySQLLexer::LOCK_SYMBOL:
            case MySQLLexer::MODIFY_SYMBOL:
            case MySQLLexer::ORDER_SYMBOL:
            case MySQLLexer::PARTITION_SYMBOL:
            case MySQLLexer::RENAME_SYMBOL:
            case MySQLLexer::REMOVE_SYMBOL:
            case MySQLLexer::REORGANIZE_SYMBOL:
                $children[] = $this->alterTableActions();
                break;

            case MySQLLexer::SECONDARY_LOAD_SYMBOL:
            case MySQLLexer::SECONDARY_UNLOAD_SYMBOL:
                if ($this->serverVersion >= 80014) {
                    $children[] = $this->alterTableActions();
                }
                break;
            case MySQLLexer::TRUNCATE_SYMBOL:
            case MySQLLexer::WITH_SYMBOL:
            case MySQLLexer::WITHOUT_SYMBOL:
                $children[] = $this->alterTableActions();
                break;

            case MySQLLexer::UPGRADE_SYMBOL:
                if ($this->serverVersion >= 50708 && $this->serverVersion < 80000) {
                    $children[] = $this->alterTableActions();
                }
                break;
        }

        return new ASTNode('alterTable', $children);
    }

    public function alterTableActions()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ADD_SYMBOL ||
            $token->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
            $token->getType() === MySQLLexer::CHANGE_SYMBOL ||
            $token->getType() === MySQLLexer::CONVERT_SYMBOL ||
            $token->getType() === MySQLLexer::DISABLE_SYMBOL ||
            $token->getType() === MySQLLexer::DROP_SYMBOL ||
            $token->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $token->getType() === MySQLLexer::FORCE_SYMBOL ||
            $token->getType() === MySQLLexer::LOCK_SYMBOL ||
            $token->getType() === MySQLLexer::MODIFY_SYMBOL ||
            $token->getType() === MySQLLexer::ORDER_SYMBOL ||
            $token->getType() === MySQLLexer::RENAME_SYMBOL ||
            $token->getType() === MySQLLexer::WITH_SYMBOL ||
            $token->getType() === MySQLLexer::WITHOUT_SYMBOL ||
            ($this->serverVersion >= 50708 && $this->serverVersion < 80000 &&
             $token->getType() === MySQLLexer::UPGRADE_SYMBOL)) {
            $children[] = $this->alterCommandList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::REMOVE_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
                    $children[] = $this->partitionClause();
                } else {
                    $children[] = $this->removePartitioning();
                }
            }
        } elseif ($token->getType() === MySQLLexer::PARTITION_SYMBOL) {
            $children[] = $this->partitionClause();
        } elseif ($token->getType() === MySQLLexer::REMOVE_SYMBOL) {
            $children[] = $this->removePartitioning();
        } elseif ($token->getType() === MySQLLexer::DISCARD_SYMBOL ||
                  $token->getType() === MySQLLexer::IMPORT_SYMBOL ||
                  $token->getType() === MySQLLexer::REORGANIZE_SYMBOL ||
                  ($this->serverVersion >= 80014 &&
                   ($this->lexer->peekNextToken()->getType() === MySQLLexer::SECONDARY_LOAD_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::SECONDARY_UNLOAD_SYMBOL)) ||
                  $token->getType() === MySQLLexer::TRUNCATE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                }
                $children[] = $this->alterCommandsModifierList();
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            }
            $children[] = $this->standaloneAlterCommands();
        } else {
            throw new \Exception('Unexpected token in alterTableActions: ' . $token->getText());
        }

        return new ASTNode('alterTableActions', $children);
    }

    public function alterCommandList()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
            $token->getType() === MySQLLexer::LOCK_SYMBOL ||
            $token->getType() === MySQLLexer::WITH_SYMBOL ||
            $token->getType() === MySQLLexer::WITHOUT_SYMBOL) {
            return $this->alterCommandsModifierList();
        } elseif ($token->getType() === MySQLLexer::ADD_SYMBOL ||
                  $token->getType() === MySQLLexer::CHANGE_SYMBOL ||
                  $token->getType() === MySQLLexer::CONVERT_SYMBOL ||
                  $token->getType() === MySQLLexer::DISABLE_SYMBOL ||
                  $token->getType() === MySQLLexer::DROP_SYMBOL ||
                  $token->getType() === MySQLLexer::ENABLE_SYMBOL ||
                  $token->getType() === MySQLLexer::FORCE_SYMBOL ||
                  $token->getType() === MySQLLexer::MODIFY_SYMBOL ||
                  $token->getType() === MySQLLexer::ORDER_SYMBOL ||
                  $token->getType() === MySQLLexer::RENAME_SYMBOL ||
                  ($this->serverVersion >= 50708 && $this->serverVersion < 80000 &&
                   $token->getType() === MySQLLexer::UPGRADE_SYMBOL)) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                $children = [];
                $children[] = $this->alterCommandsModifierList();
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->alterList();
                return new ASTNode('alterCommandList', $children);
            } else {
                return $this->alterList();
            }
        } else {
            throw new \Exception('Unexpected token in alterCommandList: ' . $token->getText());
        }
    }

    public function alterCommandsModifierList()
    {
        $children = [];

        $children[] = $this->alterCommandsModifier();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->alterCommandsModifier();
        }

        return new ASTNode('alterCommandsModifierList', $children);
    }

    public function standaloneAlterCommands()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::DISCARD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DISCARD_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::IMPORT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IMPORT_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ADD_SYMBOL ||
                  $token->getType() === MySQLLexer::ANALYZE_SYMBOL ||
                  $token->getType() === MySQLLexer::CHECK_SYMBOL ||
                  $token->getType() === MySQLLexer::COALESCE_SYMBOL ||
                  $token->getType() === MySQLLexer::DISCARD_SYMBOL ||
                  $token->getType() === MySQLLexer::DROP_SYMBOL ||
                  $token->getType() === MySQLLexer::EXCHANGE_SYMBOL ||
                  $token->getType() === MySQLLexer::IMPORT_SYMBOL ||
                  $token->getType() === MySQLLexer::OPTIMIZE_SYMBOL ||
                  $token->getType() === MySQLLexer::REBUILD_SYMBOL ||
                  $token->getType() === MySQLLexer::REORGANIZE_SYMBOL ||
                  $token->getType() === MySQLLexer::REPAIR_SYMBOL ||
                  $token->getType() === MySQLLexer::TRUNCATE_SYMBOL) {
            $children[] = $this->alterPartition();
        } elseif ($this->serverVersion >= 80014 &&
                  ($token->getType() === MySQLLexer::SECONDARY_LOAD_SYMBOL ||
                   $token->getType() === MySQLLexer::SECONDARY_UNLOAD_SYMBOL)) {
            if ($token->getType() === MySQLLexer::SECONDARY_LOAD_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SECONDARY_LOAD_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::SECONDARY_UNLOAD_SYMBOL);
            }
        } else {
            throw new \Exception('Unexpected token in standaloneAlterCommands: ' . $token->getText());
        }

        return new ASTNode('standaloneAlterCommands', $children);
    }

    public function alterCommandsModifier()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
            return $this->alterAlgorithmOption();
        } elseif ($token->getType() === MySQLLexer::LOCK_SYMBOL) {
            return $this->alterLockOption();
        } elseif ($this->serverVersion >= 50706 &&
                  ($token->getType() === MySQLLexer::WITH_SYMBOL ||
                   $token->getType() === MySQLLexer::WITHOUT_SYMBOL)) {
            return $this->withValidation();
        } else {
            throw new \Exception('Unexpected token in alterCommandsModifier: ' . $token->getText());
        }
    }

    public function alterPartition()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ADD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ADD_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
                $children[] = $this->partitionDefinitions();
            } else {
                $children[] = $this->match(MySQLLexer::PARTITIONS_SYMBOL);
                $children[] = $this->real_ulong_number();
            }
        } elseif ($token->getType() === MySQLLexer::DROP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->identifierList();
        } elseif ($token->getType() === MySQLLexer::REBUILD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REBUILD_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->allOrPartitionNameList();
        } elseif ($token->getType() === MySQLLexer::OPTIMIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPTIMIZE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->allOrPartitionNameList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
        } elseif ($token->getType() === MySQLLexer::ANALYZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ANALYZE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->allOrPartitionNameList();
        } elseif ($token->getType() === MySQLLexer::CHECK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->allOrPartitionNameList();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->checkOption();
            }
        } elseif ($token->getType() === MySQLLexer::REPAIR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPAIR_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->allOrPartitionNameList();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::USE_FRM_SYMBOL) {
                $children[] = $this->repairType();
            }
        } elseif ($token->getType() === MySQLLexer::COALESCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COALESCE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->real_ulong_number();
        } elseif ($token->getType() === MySQLLexer::TRUNCATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TRUNCATE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->allOrPartitionNameList();
        } elseif ($token->getType() === MySQLLexer::REORGANIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REORGANIZE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->identifierList();
                $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
                $children[] = $this->partitionDefinitions();
            }
        } elseif ($token->getType() === MySQLLexer::EXCHANGE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EXCHANGE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->identifier();
            $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRef();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                $children[] = $this->withValidation();
            }
        } elseif ($this->serverVersion >= 50704 && $token->getType() === MySQLLexer::DISCARD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DISCARD_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->allOrPartitionNameList();
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        } elseif ($this->serverVersion >= 50704 && $token->getType() === MySQLLexer::IMPORT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IMPORT_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->allOrPartitionNameList();
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in alterPartition: ' . $token->getText());
        }

        return new ASTNode('alterPartition', $children);
    }

    public function alterList()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ADD_SYMBOL ||
            $token->getType() === MySQLLexer::CHANGE_SYMBOL ||
            $token->getType() === MySQLLexer::CONVERT_SYMBOL ||
            $token->getType() === MySQLLexer::DISABLE_SYMBOL ||
            $token->getType() === MySQLLexer::DROP_SYMBOL ||
            $token->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $token->getType() === MySQLLexer::FORCE_SYMBOL ||
            $token->getType() === MySQLLexer::MODIFY_SYMBOL ||
            $token->getType() === MySQLLexer::ORDER_SYMBOL ||
            $token->getType() === MySQLLexer::RENAME_SYMBOL ||
            ($this->serverVersion >= 50708 && $this->serverVersion < 80000 &&
             $token->getType() === MySQLLexer::UPGRADE_SYMBOL)) {
            $children[] = $this->alterListItem();
        } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL ||
                  $token->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL ||
                  $token->getType() === MySQLLexer::AVG_ROW_LENGTH_SYMBOL ||
                  $token->getType() === MySQLLexer::CHECKSUM_SYMBOL ||
                  $token->getType() === MySQLLexer::TABLE_CHECKSUM_SYMBOL ||
                  ($this->serverVersion >= 50708 && $token->getType() === MySQLLexer::COMPRESSION_SYMBOL) ||
                  $token->getType() === MySQLLexer::CONNECTION_SYMBOL ||
                  $token->getType() === MySQLLexer::DATA_SYMBOL ||
                  $token->getType() === MySQLLexer::DELAY_KEY_WRITE_SYMBOL ||
                  ($this->serverVersion >= 50711 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) ||
                  $token->getType() === MySQLLexer::INDEX_SYMBOL ||
                  $token->getType() === MySQLLexer::INSERT_METHOD_SYMBOL ||
                  $token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                  $token->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
                  $token->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
                  $token->getType() === MySQLLexer::PACK_KEYS_SYMBOL ||
                  $token->getType() === MySQLLexer::PASSWORD_SYMBOL ||
                  $token->getType() === MySQLLexer::ROW_FORMAT_SYMBOL ||
                  $token->getType() === MySQLLexer::STATS_AUTO_RECALC_SYMBOL ||
                  $token->getType() === MySQLLexer::STATS_PERSISTENT_SYMBOL ||
                  $token->getType() === MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL ||
                  $token->getType() === MySQLLexer::STORAGE_SYMBOL ||
                  $token->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
                  $token->getType() === MySQLLexer::UNION_SYMBOL ||
                  $token->getType() === MySQLLexer::CHARSET_SYMBOL ||
                  $token->getType() === MySQLLexer::CHAR_SYMBOL ||
                  $token->getType() === MySQLLexer::COLLATE_SYMBOL) {
            $children[] = $this->createTableOptionsSpaceSeparated();
        } else {
            throw new \Exception('Unexpected token in alterList: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::ADD_SYMBOL ||
                $token->getType() === MySQLLexer::CHANGE_SYMBOL ||
                $token->getType() === MySQLLexer::CONVERT_SYMBOL ||
                $token->getType() === MySQLLexer::DISABLE_SYMBOL ||
                $token->getType() === MySQLLexer::DROP_SYMBOL ||
                $token->getType() === MySQLLexer::ENABLE_SYMBOL ||
                $token->getType() === MySQLLexer::FORCE_SYMBOL ||
                $token->getType() === MySQLLexer::MODIFY_SYMBOL ||
                $token->getType() === MySQLLexer::ORDER_SYMBOL ||
                $token->getType() === MySQLLexer::RENAME_SYMBOL ||
                ($this->serverVersion >= 50708 && $this->serverVersion < 80000 &&
                 $token->getType() === MySQLLexer::UPGRADE_SYMBOL)) {
                $children[] = $this->alterListItem();
            } elseif ($token->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
                      $token->getType() === MySQLLexer::LOCK_SYMBOL ||
                      $token->getType() === MySQLLexer::WITH_SYMBOL ||
                      $token->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                $children[] = $this->alterCommandsModifier();
            } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL ||
                      $token->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL ||
                      $token->getType() === MySQLLexer::AVG_ROW_LENGTH_SYMBOL ||
                      $token->getType() === MySQLLexer::CHECKSUM_SYMBOL ||
                      $token->getType() === MySQLLexer::TABLE_CHECKSUM_SYMBOL ||
                      ($this->serverVersion >= 50708 &&
                       $token->getType() === MySQLLexer::COMPRESSION_SYMBOL) ||
                      $token->getType() === MySQLLexer::CONNECTION_SYMBOL ||
                      $token->getType() === MySQLLexer::DATA_SYMBOL ||
                      $token->getType() === MySQLLexer::DELAY_KEY_WRITE_SYMBOL ||
                      ($this->serverVersion >= 50711 &&
                       $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) ||
                      $token->getType() === MySQLLexer::INDEX_SYMBOL ||
                      $token->getType() === MySQLLexer::INSERT_METHOD_SYMBOL ||
                      $token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                      $token->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
                      $token->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
                      $token->getType() === MySQLLexer::PACK_KEYS_SYMBOL ||
                      $token->getType() === MySQLLexer::PASSWORD_SYMBOL ||
                      $token->getType() === MySQLLexer::ROW_FORMAT_SYMBOL ||
                      $token->getType() === MySQLLexer::STATS_AUTO_RECALC_SYMBOL ||
                      $token->getType() === MySQLLexer::STATS_PERSISTENT_SYMBOL ||
                      $token->getType() === MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL ||
                      $token->getType() === MySQLLexer::STORAGE_SYMBOL ||
                      $token->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
                      $token->getType() === MySQLLexer::UNION_SYMBOL ||
                      $token->getType() === MySQLLexer::CHARSET_SYMBOL ||
                      $token->getType() === MySQLLexer::CHAR_SYMBOL ||
                      $token->getType() === MySQLLexer::COLLATE_SYMBOL) {
                $children[] = $this->createTableOptionsSpaceSeparated();
            } else {
                throw new \Exception('Unexpected token in alterList: ' . $token->getText());
            }
        }

        return new ASTNode('alterList', $children);
    }

    private function isTableConstraintDefStart($token)
    {
        return $token->getType() === MySQLLexer::PRIMARY_SYMBOL ||
               $token->getType() === MySQLLexer::UNIQUE_SYMBOL ||
               $token->getType() === MySQLLexer::KEY_SYMBOL ||
               $token->getType() === MySQLLexer::INDEX_SYMBOL ||
               $token->getType() === MySQLLexer::FULLTEXT_SYMBOL ||
               $token->getType() === MySQLLexer::SPATIAL_SYMBOL ||
               $token->getType() === MySQLLexer::FOREIGN_SYMBOL ||
               $token->getType() === MySQLLexer::CHECK_SYMBOL ||
               ($this->serverVersion >= 80017 &&
                $token->getType() === MySQLLexer::CONSTRAINT_SYMBOL);
    }

    public function alterListItem()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::ADD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ADD_SYMBOL);
            if ($this->isTableConstraintDefStart($this->lexer->peekNextToken())) {
                $children[] = $this->tableConstraintDef();
            } else {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                    $children[] = $this->identifier();
                    $children[] = $this->fieldDefinition();
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL ||
                        $this->lexer->peekNextToken()->getType() === MySQLLexer::REFERENCES_SYMBOL) {
                        $children[] = $this->checkOrReferences();
                    }
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AFTER_SYMBOL ||
                        $this->lexer->peekNextToken()->getType() === MySQLLexer::FIRST_SYMBOL) {
                        $children[] = $this->place();
                    }
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                    $children[] = $this->tableElementList();
                    $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in alterListItem: ' . $this->lexer->peekNextToken()->getText());
                }
            }
        } elseif ($token->getType() === MySQLLexer::ALTER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ALTER_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
            }
            $children[] = $this->columnInternalRef();
            if ($this->lexer->peekNextToken()->getText() === 'SET DEFAULT') {
                $children[] = $this->match(MySQLLexer::SET_SYMBOL);
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
                if ($this->serverVersion >= 80014 &&
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->exprWithParentheses();
                } else {
                    $children[] = $this->signedLiteral();
                }
            } elseif ($this->lexer->peekNextToken()->getText() === 'DROP DEFAULT') {
                $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } elseif ($this->serverVersion >= 80000 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                $children[] = $this->indexRef();
                $children[] = $this->visibility();
            } elseif ($this->serverVersion >= 80017 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
                $children[] = $this->identifier();
                $children[] = $this->constraintEnforcement();
            } elseif ($this->serverVersion >= 80019 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::CONSTRAINT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CONSTRAINT_SYMBOL);
                $children[] = $this->identifier();
                $children[] = $this->constraintEnforcement();
            } else {
                throw new \Exception('Unexpected token in alterListItem: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::CHANGE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHANGE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
            }
            $children[] = $this->columnInternalRef();
            $children[] = $this->identifier();
            $children[] = $this->fieldDefinition();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AFTER_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FIRST_SYMBOL) {
                $children[] = $this->place();
            }
        } elseif ($token->getType() === MySQLLexer::MODIFY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MODIFY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
            }
            $children[] = $this->columnInternalRef();
            $children[] = $this->fieldDefinition();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AFTER_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FIRST_SYMBOL) {
                $children[] = $this->place();
            }
        } elseif ($token->getType() === MySQLLexer::DROP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
                $children[] = $this->columnInternalRef();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESTRICT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::CASCADE_SYMBOL) {
                    $children[] = $this->restrict();
                }
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOREIGN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FOREIGN_SYMBOL);
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);

                if ($this->serverVersion >= 50700) {
                    $children[] = $this->columnInternalRef();
                } else {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                        $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                        $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                        $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                        $children[] = $this->columnInternalRef();
                    }
                }
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PRIMARY_SYMBOL);
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                }
                $children[] = $this->indexRef();
            } elseif ($this->serverVersion >= 80017 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
                $children[] = $this->identifier();
            } elseif ($this->serverVersion >= 80019 &&
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::CONSTRAINT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CONSTRAINT_SYMBOL);
                $children[] = $this->identifier();
            }
        } elseif ($token->getType() === MySQLLexer::DISABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DISABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::KEYS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ENABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ENABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::KEYS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::RENAME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RENAME_SYMBOL);

            if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMN_SYMBOL);
                $children[] = $this->columnInternalRef();
                $children[] = $this->match(MySQLLexer::TO_SYMBOL);
                $children[] = $this->identifier();
            } else {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::TO_SYMBOL);
                    } else {
                        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
                    }
                }
                $children[] = $this->tableName();
            }
        } elseif ($this->serverVersion >= 50700 &&
                  ($token->getType() === MySQLLexer::KEY_SYMBOL ||
                   $token->getType() === MySQLLexer::INDEX_SYMBOL)) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            }
            $children[] = $this->indexRef();
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->indexName();
        } elseif ($token->getType() === MySQLLexer::CONVERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONVERT_SYMBOL);
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->charset();
            if ($this->serverVersion >= 80014 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } else {
                $children[] = $this->charsetName();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL) {
                $children[] = $this->collate();
            }
        } elseif ($token->getType() === MySQLLexer::FORCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FORCE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ORDER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ORDER_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->alterOrderList();
        } elseif ($this->serverVersion >= 50708 && $this->serverVersion < 80000 &&
                  $token->getType() === MySQLLexer::UPGRADE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UPGRADE_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARTITIONING_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in alterListItem: ' . $token->getText());
        }

        return new ASTNode('alterListItem', $children);
    }

    public function alterAlgorithmOption()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ALGORITHM_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        } elseif ($this->isIdentifierStart($token)) {
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in alterAlgorithmOption: ' . $token->getText());
        }

        return new ASTNode('alterAlgorithmOption', $children);
    }

    public function alterLockOption()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        } elseif ($this->isIdentifierStart($token)) {
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in alterLockOption: ' . $token->getText());
        }

        return new ASTNode('alterLockOption', $children);
    }

    public function indexLockAndAlgorithm()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);

        if ($token1->getType() === MySQLLexer::ALGORITHM_SYMBOL &&
            ($token2->getType() === MySQLLexer::LOCK_SYMBOL || $token2->getType() === MySQLLexer::EOF)) {
            $children = [];
            $children[] = $this->alterAlgorithmOption();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
                $children[] = $this->alterLockOption();
            }
            return new ASTNode('indexLockAndAlgorithm', $children);
        } elseif ($token1->getType() === MySQLLexer::LOCK_SYMBOL &&
                  ($token2->getType() === MySQLLexer::ALGORITHM_SYMBOL || $token2->getType() === MySQLLexer::EOF)) {
            $children = [];
            $children[] = $this->alterLockOption();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                $children[] = $this->alterAlgorithmOption();
            }
            return new ASTNode('indexLockAndAlgorithm', $children);
        } else {
            throw new \Exception('Unexpected token in indexLockAndAlgorithm: ' . $token1->getText());
        }
    }

    public function place()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::AFTER_SYMBOL) {
            $this->match(MySQLLexer::AFTER_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::AFTER_SYMBOL)),
                $this->identifier()
            ];

            return new ASTNode('place', $children);
        } elseif ($token->getType() === MySQLLexer::FIRST_SYMBOL) {
            $this->match(MySQLLexer::FIRST_SYMBOL);

            return new ASTNode(
                'place',
                [
                    new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FIRST_SYMBOL))
                ]
            );
        } else {
            throw new \Exception('Unexpected token in place: ' . $token->getText());
        }
    }

    public function restrict()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::RESTRICT_SYMBOL:
        case MySQLLexer::CASCADE_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function withValidation()
    {
        $this->match($this->lexer->peekNextToken()->getType());
        $children = [new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()))];
        $children[] = $this->match(MySQLLexer::VALIDATION_SYMBOL);

        return new ASTNode('withValidation', $children);
    }

    public function alterOrderList()
    {
        $children = [];

        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASC_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DESC_SYMBOL) {
            $children[] = $this->direction();
        }
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->identifier();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASC_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DESC_SYMBOL) {
                $children[] = $this->direction();
            }
        }

        return new ASTNode('alterOrderList', $children);
    }

    public function removePartitioning()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REMOVE_SYMBOL);
        $children[] = $this->match(MySQLLexer::PARTITIONING_SYMBOL);

        return new ASTNode('removePartitioning', $children);
    }

    public function alterTablespace()
    {
        $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TABLESPACE_SYMBOL))];
        $children[] = $this->tablespaceRef();
        $token = $this->lexer->peekNextToken();

        if ($this->serverVersion < 80000) {
            if ($token->getType() === MySQLLexer::ADD_SYMBOL ||
                $token->getType() === MySQLLexer::DROP_SYMBOL) {
                $this->match($this->lexer->peekNextToken()->getType());
                $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
                $children[] = $this->match(MySQLLexer::DATAFILE_SYMBOL);
                $children[] = $this->textLiteral();
            } elseif ($token->getType() === MySQLLexer::CHANGE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CHANGE_SYMBOL);
                $children[] = $this->match(MySQLLexer::DATAFILE_SYMBOL);
                $children[] = $this->textLiteral();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_SIZE_SYMBOL) {
                    do {
                        $children[] = $this->changeTablespaceOption();
                    } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL);
                }
            } elseif ($token->getType() === MySQLLexer::READ_ONLY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::READ_ONLY_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::READ_WRITE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::READ_WRITE_SYMBOL);
            } elseif ($token->getText() === 'NOT ACCESSIBLE') {
                $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
                $children[] = $this->match(MySQLLexer::ACCESSIBLE_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in alterTablespace: ' . $token->getText());
            }
        } else {
            if (($token->getType() === MySQLLexer::ADD_SYMBOL ||
                 $token->getType() === MySQLLexer::DROP_SYMBOL) &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::DATAFILE_SYMBOL) {
                $this->match($this->lexer->peekNextToken()->getType());
                $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
                $children[] = $this->match(MySQLLexer::DATAFILE_SYMBOL);
                $children[] = $this->textLiteral();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_SIZE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WAIT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WAIT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
                    $children[] = $this->alterTablespaceOptions();
                }
            } elseif ($token->getType() === MySQLLexer::RENAME_SYMBOL) {
                $children[] = $this->match(MySQLLexer::RENAME_SYMBOL);
                $children[] = $this->match(MySQLLexer::TO_SYMBOL);
                $children[] = $this->identifier();
            } elseif ($token->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL ||
                      $token->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL ||
                      $token->getType() === MySQLLexer::MAX_SIZE_SYMBOL ||
                      $token->getType() === MySQLLexer::ENGINE_SYMBOL ||
                      $token->getType() === MySQLLexer::WAIT_SYMBOL ||
                      $token->getType() === MySQLLexer::NO_WAIT_SYMBOL ||
                      $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
                $children[] = $this->alterTablespaceOptions();
            } else {
                throw new \Exception('Unexpected token in alterTablespace: ' . $token->getText());
            }
        }

        return new ASTNode('alterTablespace', $children);
    }

    public function alterUndoTablespace()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::UNDO_SYMBOL);
        $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children[] = $this->tablespaceRef();
        $children[] = $this->match(MySQLLexer::SET_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ACTIVE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ACTIVE_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::INACTIVE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INACTIVE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in alterUndoTablespace: ' . $this->lexer->peekNextToken()->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL) {
            $children[] = $this->undoTableSpaceOptions();
        }

        return new ASTNode('alterUndoTablespace', $children);
    }

    public function undoTableSpaceOptions()
    {
        $children = [];

        $children[] = $this->undoTableSpaceOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->undoTableSpaceOption();
        }

        return new ASTNode('undoTableSpaceOptions', $children);
    }

    public function undoTableSpaceOption()
    {
        return $this->tsOptionEngine();
    }

    public function alterTablespaceOptions()
    {
        $children = [];

        $children[] = $this->alterTablespaceOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->alterTablespaceOption();
        }

        return new ASTNode('alterTablespaceOptions', $children);
    }

    public function alterTablespaceOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL) {
            return $this->tsOptionInitialSize();
        } elseif ($token->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL) {
            return $this->tsOptionAutoextendSize();
        } elseif ($token->getType() === MySQLLexer::MAX_SIZE_SYMBOL) {
            return $this->tsOptionMaxSize();
        } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            return $this->tsOptionEngine();
        } elseif ($token->getType() === MySQLLexer::WAIT_SYMBOL ||
                  $token->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            return $this->tsOptionWait();
        } elseif ($token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
            return $this->tsOptionEncryption();
        } else {
            throw new \Exception('Unexpected token in alterTablespaceOption: ' . $token->getText());
        }
    }

    public function changeTablespaceOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL) {
            return $this->tsOptionInitialSize();
        } elseif ($token->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL) {
            return $this->tsOptionAutoextendSize();
        } elseif ($token->getType() === MySQLLexer::MAX_SIZE_SYMBOL) {
            return $this->tsOptionMaxSize();
        } else {
            throw new \Exception('Unexpected token in changeTablespaceOption: ' . $token->getText());
        }
    }

    public function alterView()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
            $children[] = $this->viewAlgorithm();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        if ($this->lexer->peekNextToken()->getText() === 'SQL SECURITY') {
            $children[] = $this->viewSuid();
        }

        $children[] = $this->match(MySQLLexer::VIEW_SYMBOL);
        $children[] = $this->viewRef();
        $children[] = $this->viewTail();

        return new ASTNode('alterView', $children);
    }

    public function srsAttribute()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::NAME_SYMBOL) {
            $this->match(MySQLLexer::NAME_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NAME_SYMBOL));
        } elseif ($token->getType() === MySQLLexer::DEFINITION_SYMBOL) {
            $this->match(MySQLLexer::DEFINITION_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFINITION_SYMBOL));
        } elseif ($token->getType() === MySQLLexer::ORGANIZATION_SYMBOL) {
            $this->match(MySQLLexer::ORGANIZATION_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ORGANIZATION_SYMBOL));
            $children[] = $this->textStringNoLinebreak();
            $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::IDENTIFIED_SYMBOL));
            $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BY_SYMBOL));
            $children[] = $this->real_ulonglong_number();
            return new ASTNode('srsAttribute', $children);
        } else {
            $this->match(MySQLLexer::DESCRIPTION_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DESCRIPTION_SYMBOL));
        }
        $this->match(MySQLLexer::TEXT_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TEXT_SYMBOL));
        $children[] = $this->textStringNoLinebreak();
        return new ASTNode('srsAttribute', $children);
    }

    public function viewReplaceOrAlgorithm()
    {
        if ($this->lexer->peekNextToken()->getText() === 'OR REPLACE') {
            $children = [];

            $this->match(MySQLLexer::OR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OR_SYMBOL));
            $this->match(MySQLLexer::REPLACE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::REPLACE_SYMBOL));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                $children[] = $this->viewAlgorithm();
            }

            return new ASTNode('viewReplaceOrAlgorithm', $children);
        } else {
            $children = [];

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                $children[] = $this->viewAlgorithm();
            }

            return new ASTNode('viewReplaceOrAlgorithm', $children);
        }
    }

    public function userIdentifierOrText()
    {
        $children = [];
        $children[] = $this->textOrIdentifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL) {
            $this->match(MySQLLexer::AT_SIGN_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::AT_SIGN_SYMBOL));
            $children[] = $this->textOrIdentifier();
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $this->match(MySQLLexer::AT_TEXT_SUFFIX);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::AT_TEXT_SUFFIX));
        }

        return new ASTNode('userIdentifierOrText', $children);
    }

    // This is not the full view_tail from sql_yacc.yy as we have either a view name or a view reference,
    // depending on whether we come from createView or alterView. Everything until this difference is duplicated in those rules.
    public function viewTail()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->columnInternalRefList();
        }

        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        $children[] = $this->viewSelect();

        return new ASTNode('viewTail', $children);
    }

    public function viewSelect()
    {
        $children = [];

        $children[] = $this->queryExpressionOrParens();

        if ($this->lexer->peekNextToken()->getText() === 'WITH CHECK OPTION') {
            $children[] = $this->viewCheckOption();
        }

        return new ASTNode('viewSelect', $children);
    }

    public function viewCheckOption()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CASCADED_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CASCADED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CASCADED_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::LOCAL_SYMBOL);
            }
        }

        $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);

        return new ASTNode('viewCheckOption', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function createStatement()
    {
        $this->match(MySQLLexer::CREATE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CREATE_SYMBOL))];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DATABASE_SYMBOL) {
            $children[] = $this->createDatabase();
        } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL ||
                  $token->getType() === MySQLLexer::TEMPORARY_SYMBOL) {
            $children[] = $this->createTable();
        } elseif ($token->getType() === MySQLLexer::FUNCTION_SYMBOL) {
            $children[] = $this->createFunction();
        } elseif ($token->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
            $children[] = $this->createProcedure();
        } elseif ($token->getType() === MySQLLexer::AGGREGATE_SYMBOL) {
            $children[] = $this->createUdf();
        } elseif ($token->getType() === MySQLLexer::LOGFILE_SYMBOL) {
            $children[] = $this->createLogfileGroup();
        } elseif ($token->getType() === MySQLLexer::VIEW_SYMBOL) {
            $children[] = $this->createView();
        } elseif ($token->getType() === MySQLLexer::TRIGGER_SYMBOL) {
            $children[] = $this->createTrigger();
        } elseif ($token->getType() === MySQLLexer::INDEX_SYMBOL ||
                  $token->getType() === MySQLLexer::FULLTEXT_SYMBOL ||
                  $token->getType() === MySQLLexer::SPATIAL_SYMBOL) {
            $children[] = $this->createIndex();
        } elseif ($token->getType() === MySQLLexer::SERVER_SYMBOL) {
            $children[] = $this->createServer();
        } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
            $children[] = $this->createTablespace();
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::ROLE_SYMBOL) {
            $children[] = $this->createRole();
        } elseif ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::SPATIAL_SYMBOL) {
            $children[] = $this->createSpatialReference();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::UNDO_SYMBOL) {
            $children[] = $this->createUndoTablespace();
        } elseif ($token->getType() === MySQLLexer::ONLINE_SYMBOL ||
                  $token->getType() === MySQLLexer::OFFLINE_SYMBOL) {
            if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->createIndex();
            } else {
                $children[] = $this->createTable();
            }
        } elseif ($token->getType() === MySQLLexer::UNIQUE_SYMBOL) {
            if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->createIndex();
            } else {
                $children[] = $this->createTable();
            }
        } else {
            throw new \Exception('Unexpected token in createStatement: ' . $token->getText());
        }

        return new ASTNode('createStatement', $children);
    }

    public function createDatabase()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DATABASE_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }
        $children[] = $this->schemaName();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::CHARSET_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL ||
               ($this->serverVersion >= 80016 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCRYPTION_SYMBOL)) {
            $children[] = $this->createDatabaseOption();
        }

        return new ASTNode('createDatabase', $children);
    }

    public function createDatabaseOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
        $token->getType() === MySQLLexer::CHAR_SYMBOL ||
        $token->getType() === MySQLLexer::CHARSET_SYMBOL) {
            return $this->defaultCharset();
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                  $token->getType() === MySQLLexer::COLLATE_SYMBOL) {
            return $this->defaultCollation();
        } elseif ($this->serverVersion >= 80016 && $token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                  $this->serverVersion >= 80016 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
            return $this->defaultEncryption();
        } else {
            throw new \Exception('Unexpected token in createDatabaseOption: ' . $token->getText());
        }
    }

    public function createTable()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TEMPORARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TEMPORARY_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);

        if ($this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }

        $children[] = $this->tableName();

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::UNIQUE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FULLTEXT_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::SPATIAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FOREIGN_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL ||
                ($this->serverVersion >= 80017 &&
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CONSTRAINT_SYMBOL)) {
                $children[] = $this->tableElementList();
            }

            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

            if ($this->isCreateTableOptionStart($this->lexer->peekNextToken())) {
                $children[] = $this->createTableOptions();
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
                $children[] = $this->partitionClause();
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                $children[] = $this->duplicateAsQueryExpression();
            }
        } elseif ($token->getType() === MySQLLexer::LIKE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
            $children[] = $this->tableRef();
        } elseif ($token->getText() === 'OPEN PARENTHESIS' && $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LIKE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
            $children[] = $this->tableRef();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in createTable: ' . $token->getText());
        }

        return new ASTNode('createTable', $children);
    }
    
    public function timeFunctionParameters()
    {
        $children = [];
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INT_NUMBER) {
            $children[] = $this->fractionalPrecision();
        }
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        return new ASTNode('timeFunctionParameters', $children);
    }

    public function substringFunction()
    {
        $children = [];
        $this->match(MySQLLexer::SUBSTRING_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SUBSTRING_SYMBOL));
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        $children[] = $this->expr();
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::COMMA_SYMBOL) {
            $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::COMMA_SYMBOL));
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::COMMA_SYMBOL));
                $children[] = $this->expr();
            }
        } elseif ($token->getType() === MySQLLexer::FROM_SYMBOL) {
            $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FROM_SYMBOL));
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $this->match(MySQLLexer::FOR_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FOR_SYMBOL));
                $children[] = $this->expr();
            }
        } else {
            throw new \Exception('Unexpected token in substringFunction: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('substringFunction', $children);
    }

    public function defaultCharset()
    {
        $children = [];
        if($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
        }
        $children[] = $this->charsetClause();
        return new ASTNode('defaultCharset', $children);
    }

    public function defaultCollation()
    {
        $children = [];

        if($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
        }
        $this->match(MySQLLexer::COLLATE_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::EQUAL_OPERATOR));
        }
        $children[] = $this->collationName();
        return new ASTNode('defaultCollation', $children);
    }
    public function collationName()
    {
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::IDENTIFIER:
            case MySQLLexer::BACK_TICK_QUOTED_ID:
            case MySQLLexer::DOUBLE_QUOTED_TEXT:
            case MySQLLexer::SINGLE_QUOTED_TEXT:
                return ASTNode::fromToken($token);
            case MySQLLexer::DEFAULT_SYMBOL:
                if ($this->serverVersion < 80011) {
                    return ASTNode::fromToken($token);
                }
            case MySQLLexer::BINARY_SYMBOL:
                if ($this->serverVersion >= 80018) {
                    return ASTNode::fromToken($token);
                }
            default:
                if ($this->isIdentifierKeyword($token)) {
                    return $this->identifierKeyword();
                }

                throw new \Exception('Unexpected token in collationName: ' . $token->getText());
        }
     }
 
    public function defaultEncryption()
    {
        $children = [];
        $this->match(MySQLLexer::DEFAULT_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
        $this->match(MySQLLexer::ENCRYPTION_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ENCRYPTION_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::EQUAL_OPERATOR));
        }
        $children[] = $this->textStringLiteral();
        return new ASTNode('defaultEncryption', $children);
        }

    public function dateTimeTtype()
    {
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::DATE_SYMBOL:
            case MySQLLexer::TIME_SYMBOL:
            case MySQLLexer::DATETIME_SYMBOL:
            case MySQLLexer::TIMESTAMP_SYMBOL:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in dateTimeTtype: ' . $token->getText());
        }
    }

    public function trimFunction()
    {
        $children = [];
        $this->match(MySQLLexer::TRIM_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TRIM_SYMBOL));
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        $token = $this->lexer->peekNextToken();
        switch ($token->getType()) {
            case MySQLLexer::LEADING_SYMBOL:
                $this->match(MySQLLexer::LEADING_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::LEADING_SYMBOL));
                if ($this->isExprStart($this->lexer->peekNextToken())) {
                    $children[] = $this->expr();
                }
                $this->match(MySQLLexer::FROM_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FROM_SYMBOL));
                $children[] = $this->expr();
                break;
            case MySQLLexer::TRAILING_SYMBOL:
                $this->match(MySQLLexer::TRAILING_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TRAILING_SYMBOL));
                if ($this->isExprStart($this->lexer->peekNextToken())) {
                    $children[] = $this->expr();
                }
                $this->match(MySQLLexer::FROM_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FROM_SYMBOL));
                $children[] = $this->expr();
                break;
            case MySQLLexer::BOTH_SYMBOL:
                $this->match(MySQLLexer::BOTH_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BOTH_SYMBOL));
                if ($this->isExprStart($this->lexer->peekNextToken())) {
                    $children[] = $this->expr();
                }
                $this->match(MySQLLexer::FROM_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FROM_SYMBOL));
                $children[] = $this->expr();
                break;
            default:
                $children[] = $this->expr();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
                    $this->match(MySQLLexer::FROM_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FROM_SYMBOL));
                    $children[] = $this->expr();
                }
                break;
        }
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        return new ASTNode('trimFunction', $children);
    }

    public function typeDatetimePrecision()
    {
        $children = [];
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        $this->match(MySQLLexer::INT_NUMBER);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::INT_NUMBER));
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        return new ASTNode('typeDatetimePrecision', $children);
    }

    public function tableElementList()
    {
        $children = [];

        $children[] = $this->tableElement();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->tableElement();
        }

        return new ASTNode('tableElementList', $children);
    }

    public function tableElement()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            return $this->columnDefinition();
        } elseif ($token->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                  $token->getType() === MySQLLexer::UNIQUE_SYMBOL ||
                  $token->getType() === MySQLLexer::KEY_SYMBOL ||
                  $token->getType() === MySQLLexer::INDEX_SYMBOL ||
                  $token->getType() === MySQLLexer::FULLTEXT_SYMBOL ||
                  $token->getType() === MySQLLexer::SPATIAL_SYMBOL ||
                  $token->getType() === MySQLLexer::FOREIGN_SYMBOL ||
                  $token->getType() === MySQLLexer::CHECK_SYMBOL ||
                  $token->getType() === MySQLLexer::CONSTRAINT_SYMBOL) {
            return $this->tableConstraintDef();
        } else {
            throw new \Exception('Unexpected token in tableElement: ' . $token->getText());
        }
    }

    public function duplicateAsQueryExpression()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REPLACE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        }

        $children[] = $this->queryExpressionOrParens();
        return new ASTNode('duplicateAsQueryExpression', $children);
    }

    public function queryExpressionOrParens()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            return $this->queryExpressionParens();
        } else {
            return $this->queryExpression();
        }
    }

    public function createRoutine()
    {
        $this->match(MySQLLexer::CREATE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CREATE_SYMBOL))];

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
            $children[] = $this->createProcedure();
        } elseif ($token->getType() === MySQLLexer::FUNCTION_SYMBOL) {
            $children[] = $this->createFunction();
        } elseif ($token->getType() === MySQLLexer::AGGREGATE_SYMBOL) {
            $children[] = $this->createUdf();
        } else {
            throw new \Exception('Unexpected token in createRoutine: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SEMICOLON_SYMBOL) {
            $this->match(MySQLLexer::SEMICOLON_SYMBOL);
        }

        if ($this->lexer->peekNextToken()->getType() !== MySQLLexer::EOF) {
            throw new \Exception('Unexpected token: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('createRoutine', $children);
    }

    public function createProcedure()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
        $children[] = $this->procedureName();
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::OUT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::INOUT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->procedureParameter();

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->procedureParameter();
            }
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::CONTAINS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::READS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MODIFIES_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DETERMINISTIC_SYMBOL) {
            $children[] = $this->routineCreateOption();
        }

        $children[] = $this->compoundStatement();
        return new ASTNode('createProcedure', $children);
    }

    public function createFunction()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
        $children[] = $this->functionName();
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->functionParameter();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->functionParameter();
            }
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = $this->match(MySQLLexer::RETURNS_SYMBOL);
        $children[] = $this->typeWithOptCollate();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::CONTAINS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::READS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MODIFIES_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DETERMINISTIC_SYMBOL) {
            $children[] = $this->routineCreateOption();
        }

        $children[] = $this->compoundStatement();
        return new ASTNode('createFunction', $children);
    }

    public function createUdf()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::AGGREGATE_SYMBOL);
        $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
        $children[] = $this->udfName();
        $children[] = $this->match(MySQLLexer::RETURNS_SYMBOL);

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::STRING_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STRING_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::INT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REAL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DECIMAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DECIMAL_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in createUdf: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::SONAME_SYMBOL);
        $children[] = $this->textLiteral();
        return new ASTNode('createUdf', $children);
    }

    public function routineCreateOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::COMMENT_SYMBOL ||
            $token->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
            $token->getType() === MySQLLexer::NO_SYMBOL ||
            $token->getType() === MySQLLexer::CONTAINS_SYMBOL ||
            $token->getType() === MySQLLexer::READS_SYMBOL ||
            $token->getType() === MySQLLexer::MODIFIES_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_SYMBOL) {
            return $this->routineOption();
        } elseif ($token->getType() === MySQLLexer::DETERMINISTIC_SYMBOL ||
                  $token->getText() === 'NOT DETERMINISTIC') {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::DETERMINISTIC_SYMBOL);
            return new ASTNode('routineCreateOption', $children);
        } else {
            throw new \Exception('Unexpected token in routineCreateOption: ' . $token->getText());
        }
    }

    public function routineAlterOptions()
    {
        $children = [];

        do {
            $children[] = $this->routineCreateOption();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::LANGUAGE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CONTAINS_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::READS_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MODIFIES_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DETERMINISTIC_SYMBOL);

        return new ASTNode('routineAlterOptions', $children);
    }

    public function routineOption()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::LANGUAGE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LANGUAGE_SYMBOL);
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NO_SYMBOL);
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CONTAINS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONTAINS_SYMBOL);
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::READS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::READS_SYMBOL);
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MODIFIES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MODIFIES_SYMBOL);
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
        } elseif ($token->getText() === 'SQL SECURITY') {
            $children[] = $this->match(MySQLLexer::SQL_SYMBOL);
            $children[] = $this->match(MySQLLexer::SECURITY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFINER_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::INVOKER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INVOKER_SYMBOL);
            } else {
                throw new \Exception(
                    'Unexpected token in routineOption: ' . $this->lexer->peekNextToken()->getText()
                );
            }
        } else {
            throw new \Exception('Unexpected token in routineOption: ' . $token->getText());
        }

        return new ASTNode('routineOption', $children);
    }

    public function createIndex()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ONLINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::OFFLINE_SYMBOL) {
            $children[] = $this->onlineOption();
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNIQUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNIQUE_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                if ($this->serverVersion >= 80014 &&
                    ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL)) {
                    $children[] = $this->indexName();
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                        $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL) {
                        $children[] = $this->indexTypeClause();
                    }
                } else {
                    $children[] = $this->indexNameAndType();
                }
                $children[] = $this->createIndexTarget();
                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                    $children[] = $this->indexOption();
                }
            } else {
                $children[] = $this->indexNameAndType();
                $children[] = $this->createIndexTarget();
                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                    $children[] = $this->indexOption();
                }
            }
        } elseif ($token->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            if ($this->serverVersion >= 80014 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL)) {
                $children[] = $this->indexName();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL) {
                    $children[] = $this->indexTypeClause();
                }
            } else {
                $children[] = $this->indexNameAndType();
            }
            $children[] = $this->createIndexTarget();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->indexOption();
            }
        } elseif ($token->getType() === MySQLLexer::FULLTEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FULLTEXT_SYMBOL);
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            $children[] = $this->indexName();
            $children[] = $this->createIndexTarget();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->fulltextIndexOption();
            }
        } elseif ($token->getType() === MySQLLexer::SPATIAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SPATIAL_SYMBOL);
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            $children[] = $this->indexName();
            $children[] = $this->createIndexTarget();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->spatialIndexOption();
            }
        } else {
            throw new \Exception('Unexpected token in createIndex: '            . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children[] = $this->indexLockAndAlgorithm();
        }

        return new ASTNode('createIndex', $children);
    }

    /*
      The syntax for defining an index is:

    ... INDEX [index_name] [USING|TYPE] <index_type> ...

  The problem is that whereas USING is a reserved word, TYPE is not. We can
  still handle it if an index name is supplied, i.e.:

    ... INDEX type TYPE <index_type> ...

  here the index's name is unmbiguously 'type', but for this:

    ... INDEX TYPE <index_type> ...

  it's impossible to know what this actually mean - is 'type' the name or the
  type? For this reason we accept the TYPE syntax only if a name is supplied.
*/
    public function indexNameAndType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if (($token->getType() === MySQLLexer::IDENTIFIER ||
             $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token))) {
            $children[] = $this->indexName();
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::USING_SYMBOL) {
                $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                $children[] = $this->indexType();
            } elseif ($token->getType() === MySQLLexer::TYPE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TYPE_SYMBOL);
                $children[] = $this->indexType();
            }

            return new ASTNode('indexNameAndType', $children);
        } else {
            throw new \Exception('Unexpected token in indexNameAndType: ' . $token->getText());
        }
    }

    public function createIndexTarget()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->tableRef();
        $children[] = $this->keyListVariants();
        return new ASTNode('createIndexTarget', $children);
    }

    public function createLogfileGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOGFILE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->logfileGroupName();
        $children[] = $this->match(MySQLLexer::ADD_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNDOFILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNDOFILE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REDOFILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REDOFILE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in createLogfileGroup: ' . $token->getText());
        }

        $children[] = $this->textLiteral();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::REDO_BUFFER_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NODEGROUP_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->logfileGroupOptions();
        }

        return new ASTNode('createLogfileGroup', $children);
    }

    public function logfileGroupOptions()
    {
        $children = [];

        $children[] = $this->logfileGroupOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->logfileGroupOption();
        }

        return new ASTNode('logfileGroupOptions', $children);
    }

    public function logfileGroupOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL) {
            return $this->tsOptionInitialSize();
        } elseif ($token->getType() === MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL ||
                  $token->getType() === MySQLLexer::REDO_BUFFER_SIZE_SYMBOL) {
            return $this->tsOptionUndoRedoBufferSize();
        } elseif ($token->getType() === MySQLLexer::NODEGROUP_SYMBOL) {
            return $this->tsOptionNodegroup();
        } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            return $this->tsOptionEngine();
        } elseif ($token->getType() === MySQLLexer::WAIT_SYMBOL ||
                  $token->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            return $this->tsOptionWait();
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            return $this->tsOptionComment();
        } else {
            throw new \Exception('Unexpected token in logfileGroupOption: ' . $token->getText());
        }
    }

    public function createServer()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SERVER_SYMBOL);
        $children[] = $this->serverName();
        $children[] = $this->match(MySQLLexer::FOREIGN_SYMBOL);
        $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
        $children[] = $this->match(MySQLLexer::WRAPPER_SYMBOL);
        $children[] = $this->textOrIdentifier();
        $children[] = $this->serverOptions();

        return new ASTNode('createServer', $children);
    }

    public function serverOptions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPTIONS_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->serverOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->serverOption();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('serverOptions', $children);
    }

    // Options for CREATE/ALTER SERVER, used for the federated storage engine.
    public function serverOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::HOST_SYMBOL) {
            $children[] = $this->match(MySQLLexer::HOST_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::DATABASE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATABASE_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USER_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::PASSWORD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::SOCKET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SOCKET_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::OWNER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OWNER_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::PORT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PORT_SYMBOL);
            $children[] = $this->ulong_number();
        } else {
            throw new \Exception('Unexpected token in serverOption: ' . $token->getText());
        }

        return new ASTNode('serverOption', $children);
    }

    public function createTablespace()
    {
        $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TABLESPACE_SYMBOL))];
        $children[] = $this->tablespaceName();
        $children[] = $this->tsDataFileName();

        if ($this->lexer->peekNextToken()->getText() === 'USE LOGFILE GROUP') {
            $children[] = $this->match(MySQLLexer::USE_SYMBOL);
            $children[] = $this->match(MySQLLexer::LOGFILE_SYMBOL);
            $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
            $children[] = $this->logfileGroupRef();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENT_SIZE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NODEGROUP_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
            ($this->serverVersion >= 50707 &&
             $this->lexer->peekNextToken()->getType() === MySQLLexer::FILE_BLOCK_SIZE_SYMBOL) ||
            ($this->serverVersion >= 80014 &&
             $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCRYPTION_SYMBOL)) {
            $children[] = $this->tablespaceOptions();
        }

        return new ASTNode('createTablespace', $children);
    }

    public function createUndoTablespace()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::UNDO_SYMBOL);
        $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children[] = $this->tablespaceName();
        $children[] = $this->match(MySQLLexer::ADD_SYMBOL);
        $children[] = $this->tsDataFile();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL) {
            $children[] = $this->undoTableSpaceOptions();
        }

        return new ASTNode('createUndoTablespace', $children);
    }

    public function tsDataFileName()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->serverVersion >= 80014) {
            if ($token->getType() === MySQLLexer::ADD_SYMBOL) {
                $this->match(MySQLLexer::ADD_SYMBOL);
                $children = [
                    new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ADD_SYMBOL)),
                    $this->tsDataFile()
                ];
                return new ASTNode('tsDataFileName', $children);
            } else {
                return new ASTNode('tsDataFileName', []);
            }
        } else {
            if ($token->getType() === MySQLLexer::ADD_SYMBOL) {
                $this->match(MySQLLexer::ADD_SYMBOL);
                $children = [
                    new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ADD_SYMBOL)),
                    $this->tsDataFile()
                ];
                return new ASTNode('tsDataFileName', $children);
            } else {
                throw new \Exception(
                    'Unexpected token in tsDataFileName: ' . $token->getText()
                );
            }
        }
    }

    public function tsDataFile()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DATAFILE_SYMBOL);
        $children[] = $this->textLiteral();

        return new ASTNode('tsDataFile', $children);
    }

    public function tablespaceOptions()
    {
        $children = [];

        $children[] = $this->tablespaceOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->tablespaceOption();
        }

        return new ASTNode('tablespaceOptions', $children);
    }

    public function tablespaceOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INITIAL_SIZE_SYMBOL) {
            return $this->tsOptionInitialSize();
        } elseif ($token->getType() === MySQLLexer::AUTOEXTEND_SIZE_SYMBOL) {
            return $this->tsOptionAutoextendSize();
        } elseif ($token->getType() === MySQLLexer::MAX_SIZE_SYMBOL) {
            return $this->tsOptionMaxSize();
        } elseif ($token->getType() === MySQLLexer::EXTENT_SIZE_SYMBOL) {
            return $this->tsOptionExtentSize();
        } elseif ($token->getType() === MySQLLexer::NODEGROUP_SYMBOL) {
            return $this->tsOptionNodegroup();
        } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            return $this->tsOptionEngine();
        } elseif ($token->getType() === MySQLLexer::WAIT_SYMBOL ||
                  $token->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            return $this->tsOptionWait();
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            return $this->tsOptionComment();
        } elseif ($this->serverVersion >= 50707 && $token->getType() === MySQLLexer::FILE_BLOCK_SIZE_SYMBOL) {
            return $this->tsOptionFileblockSize();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
            return $this->tsOptionEncryption();
        } else {
            throw new \Exception('Unexpected token in tablespaceOption: ' . $token->getText());
        }
    }

    public function tsOptionInitialSize()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::INITIAL_SIZE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionInitialSize', $children);
    }

    public function tsOptionUndoRedoBufferSize()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REDO_BUFFER_SIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REDO_BUFFER_SIZE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in tsOptionUndoRedoBufferSize: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionUndoRedoBufferSize', $children);
    }

    public function tsOptionAutoextendSize()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::AUTOEXTEND_SIZE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionAutoextendSize', $children);
    }

    public function tsOptionMaxSize()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::MAX_SIZE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionMaxSize', $children);
    }

    public function tsOptionExtentSize()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::EXTENT_SIZE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionExtentSize', $children);
    }

    public function tsOptionNodegroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::NODEGROUP_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->real_ulong_number();
        return new ASTNode('tsOptionNodegroup', $children);
    }

    public function tsOptionEngine()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STORAGE_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->engineRef();
        return new ASTNode('tsOptionEngine', $children);
    }

    public function tsOptionWait()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::WAIT_SYMBOL:
        case MySQLLexer::NO_WAIT_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function tsOptionComment()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }
        $children[] = $this->textLiteral();
        return new ASTNode('tsOptionComment', $children);
    }

    public function tsOptionFileblockSize()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::FILE_BLOCK_SIZE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->sizeNumber();
        return new ASTNode('tsOptionFileblockSize', $children);
    }

    public function tsOptionEncryption()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ENCRYPTION_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }
        $children[] = $this->textStringLiteral();
        return new ASTNode('tsOptionEncryption', $children);
    }

    public function createTrigger()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        $children[] = $this->match(MySQLLexer::TRIGGER_SYMBOL);
        $children[] = $this->triggerName();

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::BEFORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BEFORE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::AFTER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AFTER_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in createTrigger: ' . $token->getText());
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INSERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSERT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::UPDATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DELETE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DELETE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in createTrigger: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->tableRef();
        $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
        $children[] = $this->match(MySQLLexer::EACH_SYMBOL);
        $children[] = $this->match(MySQLLexer::ROW_SYMBOL);

        if ($this->serverVersion >= 50700 &&
            ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOLLOWS_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::PRECEDES_SYMBOL)) {
            $children[] = $this->triggerFollowsPrecedesClause();
        }

        $children[] = $this->compoundStatement();
        return new ASTNode('createTrigger', $children);
    }

    public function triggerFollowsPrecedesClause()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::FOLLOWS_SYMBOL) {
            $this->match(MySQLLexer::FOLLOWS_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FOLLOWS_SYMBOL)),
                $this->textOrIdentifier()
            ];
            return new ASTNode('triggerFollowsPrecedesClause', $children);
        } elseif ($token->getType() === MySQLLexer::PRECEDES_SYMBOL) {
            $this->match(MySQLLexer::PRECEDES_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PRECEDES_SYMBOL)),
                $this->textOrIdentifier()
            ];
            return new ASTNode('triggerFollowsPrecedesClause', $children);
        } else {
            throw new \Exception('Unexpected token in triggerFollowsPrecedesClause: ' . $token->getText());
        }
    }

    public function createEvent()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }

        $children[] = $this->match(MySQLLexer::EVENT_SYMBOL);

        if ($this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }

        $children[] = $this->eventName();
        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->match(MySQLLexer::SCHEDULE_SYMBOL);
        $children[] = $this->schedule();

        if ($this->lexer->peekNextToken()->getText() === 'ON COMPLETION') {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->match(MySQLLexer::COMPLETION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::PRESERVE_SYMBOL);
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DISABLE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ENABLE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::DISABLE_SYMBOL);
                if ($this->lexer->peekNextToken()->getText() === 'ON SLAVE') {
                    $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                    $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
                }
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            $children[] = $this->textLiteral();
        }

        $children[] = $this->match(MySQLLexer::DO_SYMBOL);
        $children[] = $this->compoundStatement();
        return new ASTNode('createEvent', $children);
    }

    public function createRole()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);

        if ($this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }

        $children[] = $this->roleList();
        return new ASTNode('createRole', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function dropStatement()
    {
        $this->match(MySQLLexer::DROP_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DROP_SYMBOL))];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DATABASE_SYMBOL) {
            $children[] = $this->dropDatabase();
        } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL ||
                  $token->getType() === MySQLLexer::TEMPORARY_SYMBOL) {
            $children[] = $this->dropTable();
        } elseif ($token->getType() === MySQLLexer::FUNCTION_SYMBOL) {
            $children[] = $this->dropFunction();
        } elseif ($token->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
            $children[] = $this->dropProcedure();
        } elseif ($token->getType() === MySQLLexer::VIEW_SYMBOL) {
            $children[] = $this->dropView();
        } elseif ($token->getType() === MySQLLexer::TRIGGER_SYMBOL) {
            $children[] = $this->dropTrigger();
        } elseif ($token->getType() === MySQLLexer::EVENT_SYMBOL) {
            $children[] = $this->dropEvent();
        } elseif ($token->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->dropIndex();
        } elseif ($token->getType() === MySQLLexer::SERVER_SYMBOL) {
            $children[] = $this->dropServer();
        } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
            $children[] = $this->dropTableSpace();
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::ROLE_SYMBOL) {
            $children[] = $this->dropRole();
        } elseif ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::SPATIAL_SYMBOL) {
            $children[] = $this->dropSpatialReference();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::UNDO_SYMBOL) {
            $children[] = $this->dropUndoTablespace();
        } elseif ($token->getType() === MySQLLexer::ONLINE_SYMBOL ||
                  $token->getType() === MySQLLexer::OFFLINE_SYMBOL) {
            if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->dropIndex();
            } else {
                $children[] = $this->dropTable();
            }
        } else {
            throw new \Exception('Unexpected token in dropStatement: ' . $token->getText());
        }

        return new ASTNode('dropStatement', $children);
    }

    public function dropDatabase()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DATABASE_SYMBOL);

        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }

        $children[] = $this->schemaRef();

        return new ASTNode('dropDatabase', $children);
    }

    public function dropEvent()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::EVENT_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->eventRef();
        return new ASTNode('dropEvent', $children);
    }

    public function dropFunction()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->functionRef();
        return new ASTNode('dropFunction', $children);
    }

    public function dropProcedure()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->procedureRef();
        return new ASTNode('dropProcedure', $children);
    }

    public function dropIndex()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ONLINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::OFFLINE_SYMBOL) {
            $children[] = $this->onlineOption();
        }

        $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
        $children[] = $this->indexRef();
        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->tableRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children[] = $this->indexLockAndAlgorithm();
        }

        return new ASTNode('dropIndex', $children);
    }

    public function dropLogfileGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOGFILE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->logfileGroupRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            $children[] = $this->dropLogfileGroupOption();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->dropLogfileGroupOption();
            }
        }

        return new ASTNode('dropLogfileGroup', $children);
    }

    public function dropLogfileGroupOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            return $this->tsOptionEngine();
        } elseif ($token->getType() === MySQLLexer::WAIT_SYMBOL ||
                  $token->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            return $this->tsOptionWait();
        } else {
            throw new \Exception('Unexpected token in dropLogfileGroupOption: ' . $token->getText());
        }
    }

    public function dropServer()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SERVER_SYMBOL);

        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }

        $children[] = $this->serverRef();
        return new ASTNode('dropServer', $children);
    }

    public function dropTable()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TEMPORARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TEMPORARY_SYMBOL);
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TABLES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in dropTable: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }

        $children[] = $this->tableRefList();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESTRICT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::CASCADE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESTRICT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::RESTRICT_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::CASCADE_SYMBOL);
            }
        }

        return new ASTNode('dropTable', $children);
    }

    public function dropTableSpace()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children[] = $this->tablespaceRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::WAIT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WAIT_SYMBOL) {
            $children[] = $this->dropLogfileGroupOption();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->dropLogfileGroupOption();
            }
        }

        return new ASTNode('dropTableSpace', $children);
    }

    public function dropTrigger()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::TRIGGER_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->triggerRef();

        return new ASTNode('dropTrigger', $children);
    }

    public function dropView()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::VIEW_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->viewRefList();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESTRICT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::CASCADE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESTRICT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::RESTRICT_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::CASCADE_SYMBOL);
            }
        }

        return new ASTNode('dropView', $children);
    }

    public function dropRole()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->roleList();
        return new ASTNode('dropRole', $children);
    }

    public function dropSpatialReference()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SPATIAL_SYMBOL);
        $children[] = $this->match(MySQLLexer::REFERENCE_SYMBOL);
        $children[] = $this->match(MySQLLexer::SYSTEM_SYMBOL);
        if ($this->lexer->peekNextToken()->getText() === 'IF EXISTS') {
            $children[] = $this->ifExists();
        }
        $children[] = $this->real_ulonglong_number();
        return new ASTNode('dropSpatialReference', $children);
    }

    public function dropUndoTablespace()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::UNDO_SYMBOL);
        $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
        $children[] = $this->tablespaceRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL) {
            $children[] = $this->undoTableSpaceOptions();
        }

        return new ASTNode('dropUndoTablespace', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function renameTableStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::RENAME_SYMBOL);

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TABLES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in renameTableStatement: ' . $token->getText());
        }

        $children[] = $this->renamePair();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->renamePair();
        }

        return new ASTNode('renameTableStatement', $children);
    }

    public function renamePair()
    {
        $children = [];

        $children[] = $this->tableRef();
        $children[] = $this->match(MySQLLexer::TO_SYMBOL);
        $children[] = $this->tableName();

        return new ASTNode('renamePair', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function truncateTableStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::TRUNCATE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        }

        $children[] = $this->tableRef();

        return new ASTNode('truncateTableStatement', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function importStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::IMPORT_SYMBOL);
        $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
        $children[] = $this->textStringLiteralList();

        return new ASTNode('importStatement', $children);
    }

    //--------------- DML statements ---------------------------------------------------------------------------------------

    public function callStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CALL_SYMBOL);
        $children[] = $this->procedureRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->isBoolPriStart($this->lexer->peekNextToken())) {
                $children[] = $this->exprList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        }

        return new ASTNode('callStatement', $children);
    }

    public function deleteStatement()
    {
        $children = [];

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->withClause();
        }

        $children[] = $this->match(MySQLLexer::DELETE_SYMBOL);
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->deleteStatementOption();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL
            ) {
                $children[] = $this->tableAliasRefList();
                if($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                    $children[] = $this->tableReferenceList();
                }
            } else {
                $children[] = $this->tableRef();
                if ($this->serverVersion >= 80017 &&
                    ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                     $this->isIdentifierKeyword($this->lexer->peekNextToken()))) {
                    $children[] = $this->tableAlias();
                }
            }

            // Technically, these clauses are only supported in the second code branch
            // above, but it's much easier to always scan for them than it is to distinguish
            // between tableAliasRefList and tableRef.
            // It doesn't seem like a big problem, either.
            if ($this->serverVersion >= 50602 && $this->lexer->peekNextToken()->getText() === 'PARTITION') {
                $children[] = $this->partitionDelete();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                $children[] = $this->whereClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
                $children[] = $this->orderClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                $children[] = $this->simpleLimitClause();
            }
        } else {
            $children[] = $this->tableAliasRefList();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->tableReferenceList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                $children[] = $this->whereClause();
            }
        }

        return new ASTNode('deleteStatement', $children);
    }

    public function partitionDelete()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->identifierList();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('partitionDelete', $children);
    }

    public function deleteStatementOption()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::LOW_PRIORITY_SYMBOL:
        case MySQLLexer::QUICK_SYMBOL:
        case MySQLLexer::IGNORE_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function doStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DO_SYMBOL);

        if ($this->serverVersion < 50709) {
            $children[] = $this->exprList();
        } else {
            $children[] = $this->selectItemList();
        }

        return new ASTNode('doStatement', $children);
    }

    public function handlerStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::HANDLER_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->tableRef();
            $children[] = $this->match(MySQLLexer::OPEN_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->tableAlias();
            }
        } else {
            $children[] = $this->identifier();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CLOSE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CLOSE_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::READ_SYMBOL) {
                $children[] = $this->match(MySQLLexer::READ_SYMBOL);
                $children[] = $this->handlerReadOrScan();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->whereClause();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
            } else {
                throw new \Exception('Unexpected token in handlerStatement: ' . $this->lexer->peekNextToken()->getText());
            }
        }

        return new ASTNode('handlerStatement', $children);
    }

    public function handlerReadOrScan()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::FIRST_SYMBOL ||
            $token->getType() === MySQLLexer::NEXT_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FIRST_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FIRST_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::NEXT_SYMBOL);
            }
        } else {
            $children[] = $this->identifier();
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::FIRST_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FIRST_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::NEXT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NEXT_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::PREV_SYMBOL) {
                $this->match(MySQLLexer::PREV_SYMBOL);
                $children[] = ASTNode::fromToken($token);
            } elseif ($token->getType() === MySQLLexer::LAST_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LAST_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::EQUAL_OPERATOR ||
                      $token->getType() === MySQLLexer::LESS_THAN_OPERATOR ||
                      $token->getType() === MySQLLexer::GREATER_THAN_OPERATOR ||
                      $token->getType() === MySQLLexer::LESS_OR_EQUAL_OPERATOR ||
                      $token->getType() === MySQLLexer::GREATER_OR_EQUAL_OPERATOR) {
                if ($token->getType() === MySQLLexer::EQUAL_OPERATOR) {
                    $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
                } elseif ($token->getType() === MySQLLexer::LESS_THAN_OPERATOR) {
                    $children[] = $this->match(MySQLLexer::LESS_THAN_OPERATOR);
                } elseif ($token->getType() === MySQLLexer::GREATER_THAN_OPERATOR) {
                    $children[] = $this->match(MySQLLexer::GREATER_THAN_OPERATOR);
                } elseif ($token->getType() === MySQLLexer::LESS_OR_EQUAL_OPERATOR) {
                    $children[] = $this->match(MySQLLexer::LESS_OR_EQUAL_OPERATOR);
                } elseif ($token->getType() === MySQLLexer::GREATER_OR_EQUAL_OPERATOR) {
                    $children[] = $this->match(MySQLLexer::GREATER_OR_EQUAL_OPERATOR);
                } else {
                    throw new \Exception('Unexpected token in handlerReadOrScan: ' . $token->getText());
                }

                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = $this->values();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in handlerReadOrScan: ' . $token->getText());
            }
        }

        return new ASTNode('handlerReadOrScan', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function insertStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::INSERT_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DELAYED_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::HIGH_PRIORITY_SYMBOL) {
            $children[] = $this->insertLockOption();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
        }
        $children[] = $this->tableRef();

        if ($this->serverVersion >= 50602 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
            $children[] = $this->usePartition();
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            $token->getType() === MySQLLexer::VALUES_SYMBOL ||
            $token->getType() === MySQLLexer::VALUE_SYMBOL) {
            $children[] = $this->insertFromConstructor();

            if ($this->serverVersion >= 80018 && $this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                $children[] = $this->valuesReference();
            }
        } elseif ($token->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->updateList();

            if ($this->serverVersion >= 80018 && $this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                $children[] = $this->valuesReference();
            }
        } else {
            $children[] = $this->insertQueryExpression();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL &&
            $this->lexer->peekNextToken(2)->getType() === MySQLLexer::DUPLICATE_SYMBOL &&
            $this->lexer->peekNextToken(3)->getType() === MySQLLexer::KEY_SYMBOL &&
            $this->lexer->peekNextToken(4)->getType() === MySQLLexer::UPDATE_SYMBOL) {
            $children[] = $this->insertUpdateList();
        }

        return new ASTNode('insertStatement', $children);
    }

    public function insertLockOption()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::LOW_PRIORITY_SYMBOL:
        case MySQLLexer::DELAYED_SYMBOL:
        case MySQLLexer::HIGH_PRIORITY_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function insertFromConstructor()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->fields();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        }

        $children[] = $this->insertValues();
        return new ASTNode('insertFromConstructor', $children);
    }

    public function fields()
    {
        $children = [];

        $children[] = $this->insertIdentifier();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->insertIdentifier();
        }

        return new ASTNode('fields', $children);
    }

    public function insertValues()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::VALUES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VALUES_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::VALUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VALUE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in insertValues: ' . $token->getText());
        }

        $children[] = $this->valueList();
        return new ASTNode('insertValues', $children);
    }

    public function insertQueryExpression()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->fields();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        }

        $children[] = $this->queryExpressionOrParens();
        return new ASTNode('insertQueryExpression', $children);
    }

    public function valueList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);

        if ($this->isExprStart($this->lexer->peekNextToken()) ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->values();
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->isExprStart($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->values();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        }

        return new ASTNode('valueList', $children);
    }

    public function values()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($this->isExprStart($token)) {
            $children[] = $this->expr();
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in values: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($this->isExprStart($token)) {
                $children[] = $this->expr();
            } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in values: ' . $token->getText());
            }
        }

        return new ASTNode('values', $children);
    }

    public function valuesReference()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->columnInternalRefList();
        }

        return new ASTNode('valuesReference', $children);
    }

    public function insertUpdateList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->match(MySQLLexer::DUPLICATE_SYMBOL);
        $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
        $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
        $children[] = $this->updateList();
        return new ASTNode('insertUpdateList', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function loadStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOAD_SYMBOL);
        $children[] = $this->dataOrXml();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::CONCURRENT_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LOW_PRIORITY_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::CONCURRENT_SYMBOL);
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOCAL_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::INFILE_SYMBOL);
        $children[] = $this->textLiteral();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REPLACE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
            }
        }
        $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
        $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        $children[] = $this->tableRef();

        if ($this->serverVersion >= 50602 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
            $children[] = $this->usePartition();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CHARSET_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::CHAR_SYMBOL) {
            $children[] = $this->charsetClause();
        }

        if ($this->lexer->peekNextToken()->getText() === 'ROWS IDENTIFIED BY') {
            $children[] = $this->xmlRowsIdentifiedBy();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FIELDS_SYMBOL) {
            $children[] = $this->fieldsClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LINES_SYMBOL) {
            $children[] = $this->linesClause();
        }

        $children[] = $this->loadDataFileTail();
        return new ASTNode('loadStatement', $children);
    }

    public function dataOrXml()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::DATA_SYMBOL:
        case MySQLLexer::XML_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function xmlRowsIdentifiedBy()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ROWS_SYMBOL);
        $children[] = $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        $children[] = $this->textString();
        return new ASTNode('xmlRowsIdentifiedBy', $children);
    }

    public function loadDataFileTail()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
            $children[] = $this->match(MySQLLexer::INT_NUMBER);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LINES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LINES_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROWS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ROWS_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in loadDataFileTail: ' . $this->lexer->peekNextToken()->getText());
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->loadDataFileTargetList();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->updateList();
        }

        return new ASTNode('loadDataFileTail', $children);
    }

    public function loadDataFileTargetList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->fieldOrVariableList();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('loadDataFileTargetList', $children);
    }

    public function fieldOrVariableList()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->columnRef();
        } elseif ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                  $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->userVariable();
        } else {
            throw new \Exception('Unexpected token in fieldOrVariableList: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::IDENTIFIER ||
                $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($token) ||
                $token->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->columnRef();
            } elseif ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                      $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
                $children[] = $this->userVariable();
            } else {
                throw new \Exception('Unexpected token in fieldOrVariableList: ' . $token->getText());
            }
        }

        return new ASTNode('fieldOrVariableList', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function replaceStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REPLACE_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DELAYED_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LOW_PRIORITY_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::DELAYED_SYMBOL);
            }
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
        }
        $children[] = $this->tableRef();

        if ($this->serverVersion >= 50602 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
            $children[] = $this->usePartition();
        }

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            $token->getType() === MySQLLexer::VALUES_SYMBOL ||
            $token->getType() === MySQLLexer::VALUE_SYMBOL) {
            $children[] = $this->insertFromConstructor();
        } elseif ($token->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->updateList();
        } else {
            $children[] = $this->insertQueryExpression();
        }

        return new ASTNode('replaceStatement', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function selectStatement()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            $token->getType() === MySQLLexer::WITH_SYMBOL ||
            $token->getType() === MySQLLexer::UNION_SYMBOL ||
            $token->getType() === MySQLLexer::SELECT_SYMBOL) {
            $children = [];
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->queryExpressionParens();
            } else {
                $children[] = $this->queryExpression();
            }
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
                $children[] = $this->lockingClauseList();
            }
            return new ASTNode('selectStatement', $children);
        } elseif ($token->getType() === MySQLLexer::FOR_SYMBOL ||
                  $token->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children = [];
            $children[] = $this->lockingClauseList();
            $children[] = $this->intoClause();
            return new ASTNode('selectStatementWithInto', $children);
        } else {
            return $this->selectStatementWithInto();
        }
    }

    /*
      From the server grammar:

  MySQL has a syntax extension that allows into clauses in any one of two
  places. They may appear either before the from clause or at the end. All in
  a top-level select statement. This extends the standard syntax in two
  ways. First, we don't have the restriction that the result can contain only
  one row: the into clause might be INTO OUTFILE/DUMPFILE in which case any
  number of rows is allowed. Hence MySQL does not have any special case for
  the standard's <select statement: single row>. Secondly, and this has more
  severe implications for the parser, it makes the grammar ambiguous, because
  in a from-clause-less select statement with an into clause, it is not clear
  whether the into clause is the leading or the trailing one.

  While it's possible to write an unambiguous grammar, it would force us to
  duplicate the entire <select statement> syntax all the way down to the <into
  clause>. So instead we solve it by writing an ambiguous grammar and use
  precedence rules to sort out the shift/reduce conflict.

  The problem is when the parser has seen SELECT <select list>, and sees an
  INTO token. It can now either shift it or reduce what it has to a table-less
  query expression. If it shifts the token, it will accept seeing a FROM token
  next and hence the INTO will be interpreted as the leading INTO. If it
  reduces what it has seen to a table-less select, however, it will interpret
  INTO as the trailing into. But what if the next token is FROM? Obviously,
  we want to always shift INTO. We do this by two precedence declarations: We
  make the INTO token right-associative, and we give it higher precedence than
  an empty from clause, using the artificial token EMPTY_FROM_CLAUSE.

  The remaining problem is that now we allow the leading INTO anywhere, when
  it should be allowed on the top level only. We solve this by manually
  throwing parse errors whenever we reduce a nested query expression if it
  contains an into clause.
*/
    public function selectStatementWithInto()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->selectStatementWithInto();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
                  $token->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->queryExpression();
            $children[] = $this->intoClause();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
                $children[] = $this->lockingClauseList();
            }
        } else {
            throw new \Exception('Unexpected token in selectStatementWithInto: ' . $token->getText());
        }

        return new ASTNode('selectStatementWithInto', $children);
    }

    public function queryExpression()
    {
        $children = [];

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->withClause();
        }

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
            $token->getType() === MySQLLexer::VALUES_SYMBOL ||
            ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::TABLE_SYMBOL) ||
            $this->isSimpleExprStart($token)) {
            $children[] = $this->queryExpressionBody();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
                $children[] = $this->orderClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                $children[] = $this->limitClause();
            }
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->queryExpressionParens();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
                $children[] = $this->orderClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                $children[] = $this->limitClause();
            }
        } else {
            throw new \Exception('Unexpected token in queryExpression: ' . $token->getText());
        }

        if ($this->serverVersion < 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
            $children[] = $this->procedureAnalyseClause();
        }

        return new ASTNode('queryExpression', $children);
    }

    public function queryExpressionBody()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if       ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
            $token->getType() === MySQLLexer::VALUES_SYMBOL ||
            ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::TABLE_SYMBOL) ||
            $this->isSimpleExprStart($token)) {
            $children[] = $this->queryPrimary();
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->queryExpressionParens();
            $children[] = $this->match(MySQLLexer::UNION_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DISTINCT_SYMBOL) {
                $children[] = $this->unionOption();
            }

            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
                $token->getType() === MySQLLexer::VALUES_SYMBOL ||
                ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::TABLE_SYMBOL) ||
                $this->isSimpleExprStart($token)) {
                $children[] = $this->queryPrimary();
            } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->queryExpressionParens();
            } else {
                throw new \Exception('Unexpected token in queryExpressionBody: ' . $token->getText());
            }
        } else {
            throw new \Exception('Unexpected token in queryExpressionBody: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DISTINCT_SYMBOL) {
                $children[] = $this->unionOption();
            }
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
                $token->getType() === MySQLLexer::VALUES_SYMBOL ||
                ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::TABLE_SYMBOL) ||
                $this->isSimpleExprStart($token)) {
                $children[] = $this->queryPrimary();
            } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->queryExpressionParens();
            } else {
                throw new \Exception('Unexpected token in queryExpressionBody: ' . $token->getText());
            }
        }

        return new ASTNode('queryExpressionBody', $children);
    }

    public function queryExpressionParens()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->queryExpressionParens();
        } else {
            $children[] = $this->queryExpression();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
                $children[] = $this->lockingClauseList();
            }
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('queryExpressionParens', $children);
    }

    public function queryPrimary()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SELECT_SYMBOL) {
            return $this->querySpecification();
        } elseif ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::VALUES_SYMBOL) {
            return $this->tableValueConstructor();
        } elseif ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::TABLE_SYMBOL) {
            return $this->explicitTable();
        } elseif ($this->isSimpleExprStart($token)) {
            $children = [];
            $children[] = $this->simpleExpr();
            return new ASTNode('queryPrimary', $children);
        } else {
            throw new \Exception('Unexpected token in queryPrimary: ' . $token->getText());
        }
    }

    public function querySpecification()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SELECT_SYMBOL);

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DISTINCT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::HIGH_PRIORITY_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_SMALL_RESULT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_BIG_RESULT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_BUFFER_RESULT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_CALC_FOUND_ROWS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_NO_CACHE_SYMBOL ||
               ($this->serverVersion < 80000 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_CACHE_SYMBOL) ||
               ($this->serverVersion >= 50704 && $this->serverVersion < 50708 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_STATEMENT_TIME_SYMBOL)) {
            $children[] = $this->selectOption();
        }

        $children[] = $this->selectItemList();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTO_SYMBOL) {
            $children[] = $this->intoClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
            $children[] = $this->fromClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
            $children[] = $this->whereClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::GROUP_SYMBOL) {
            $children[] = $this->groupByClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::HAVING_SYMBOL) {
            $children[] = $this->havingClause();
        }

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::WINDOW_SYMBOL) {
            $children[] = $this->windowClause();
        }

        return new ASTNode('querySpecification', $children);
    }

    public function subquery()
    {
        return $this->queryExpressionParens();
    }

    public function querySpecOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ALL_SYMBOL) {
            return $this->match(MySQLLexer::ALL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DISTINCT_SYMBOL) {
            return $this->match(MySQLLexer::DISTINCT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL) {
            return $this->match(MySQLLexer::STRAIGHT_JOIN_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::HIGH_PRIORITY_SYMBOL) {
            return $this->match(MySQLLexer::HIGH_PRIORITY_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SQL_SMALL_RESULT_SYMBOL) {
            return $this->match(MySQLLexer::SQL_SMALL_RESULT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SQL_BIG_RESULT_SYMBOL) {
            return $this->match(MySQLLexer::SQL_BIG_RESULT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SQL_BUFFER_RESULT_SYMBOL) {
            return $this->match(MySQLLexer::SQL_BUFFER_RESULT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SQL_CALC_FOUND_ROWS_SYMBOL) {
            return $this->match(MySQLLexer::SQL_CALC_FOUND_ROWS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SQL_NO_CACHE_SYMBOL) {
            return $this->match(MySQLLexer::SQL_NO_CACHE_SYMBOL);
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::SQL_CACHE_SYMBOL) {
            return $this->match(MySQLLexer::SQL_CACHE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in querySpecOption: ' . $token->getText());
        }
    }

    public function limitClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LIMIT_SYMBOL);
        $children[] = $this->limitOptions();
        return new ASTNode('limitClause', $children);
    }

    public function simpleLimitClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LIMIT_SYMBOL);
        $children[] = $this->limitOption();
        return new ASTNode('simpleLimitClause', $children);
    }

    public function limitOptions()
    {
        $children = [];

        $children[] = $this->limitOption();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::OFFSET_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::OFFSET_SYMBOL);
            }
            $children[] = $this->limitOption();
        }

        return new ASTNode('limitOptions', $children);
    }

    public function limitOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            return $this->identifier();
        } elseif ($token->getType() === MySQLLexer::PARAM_MARKER ||
                  $token->getType() === MySQLLexer::ULONGLONG_NUMBER ||
                  $token->getType() === MySQLLexer::LONG_NUMBER ||
                  $token->getType() === MySQLLexer::INT_NUMBER) {
            $this->match($this->lexer->peekNextToken()->getType());
            return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        } else {
            throw new \Exception('Unexpected token in limitOption: ' . $token->getText());
        }
    }

    public function intoClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OUTFILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OUTFILE_SYMBOL);
            $children[] = $this->textStringLiteral();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CHARSET_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::CHAR_SYMBOL) {
                $children[] = $this->charsetClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FIELDS_SYMBOL) {
                $children[] = $this->fieldsClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LINES_SYMBOL) {
                $children[] = $this->linesClause();
            }
        } elseif ($token->getType() === MySQLLexer::DUMPFILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DUMPFILE_SYMBOL);
            $children[] = $this->textStringLiteral();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token) ||
                  $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
                  $token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                  $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->textOrIdentifier();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->textOrIdentifier();
            }
        } else {
            throw new \Exception('Unexpected token in intoClause: ' . $token->getText());
        }

        return new ASTNode('intoClause', $children);
    }

    public function procedureAnalyseClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
        $children[] = $this->match(MySQLLexer::ANALYSE_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INT_NUMBER) {
            $children[] = $this->match(MySQLLexer::INT_NUMBER);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->match(MySQLLexer::INT_NUMBER);
            }
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('procedureAnalyseClause', $children);
    }

    public function havingClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::HAVING_SYMBOL);
        $children[] = $this->expr();

        return new ASTNode('havingClause', $children);
    }

    public function windowClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WINDOW_SYMBOL);
        $children[] = $this->windowDefinition();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->windowDefinition();
        }

        return new ASTNode('windowClause', $children);
    }

    public function windowDefinition()
    {
        $children = [];

        $children[] = $this->windowName();
        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        $children[] = $this->windowSpec();

        return new ASTNode('windowDefinition', $children);
    }

    public function windowSpec()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->windowSpecDetails();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('windowSpec', $children);
    }

    public function windowSpecDetails()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->windowName();
        }

        if ($this->lexer->peekNextToken()->getText() === 'PARTITION BY') {
            $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->orderList();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
            $children[] = $this->orderClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROWS_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::RANGE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::GROUPS_SYMBOL) {
            $children[] = $this->windowFrameClause();
        }

        return new ASTNode('windowSpecDetails', $children);
    }

    public function windowFrameClause()
    {
        $children = [];

        $children[] = $this->windowFrameUnits();
        $children[] = $this->windowFrameExtent();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXCLUDE_SYMBOL) {
            $children[] = $this->windowFrameExclusion();
        }

        return new ASTNode('windowFrameClause', $children);
    }

    public function windowFrameUnits()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::ROWS_SYMBOL:
        case MySQLLexer::RANGE_SYMBOL:
        case MySQLLexer::GROUPS_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function windowFrameExtent()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BETWEEN_SYMBOL) {
            return $this->windowFrameBetween();
        } else {
            return $this->windowFrameStart();
        }
    }

    public function windowFrameStart()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::UNBOUNDED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNBOUNDED_SYMBOL);
            $children[] = $this->match(MySQLLexer::PRECEDING_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ULONGLONG_NUMBER) {
            $children[] = $this->match(MySQLLexer::ULONGLONG_NUMBER);
            $children[] = $this->match(MySQLLexer::PRECEDING_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PARAM_MARKER) {
            $children[] = $this->match(MySQLLexer::PARAM_MARKER);
            $children[] = $this->match(MySQLLexer::PRECEDING_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::INTERVAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->interval();
            $children[] = $this->match(MySQLLexer::PRECEDING_SYMBOL);
        } elseif ($token->getText() === 'CURRENT ROW') {
            $children[] = $this->match(MySQLLexer::CURRENT_SYMBOL);
            $children[] = $this->match(MySQLLexer::ROW_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in windowFrameStart: ' . $token->getText());
        }

        return new ASTNode('windowFrameStart', $children);
    }

    public function windowFrameBetween()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::BETWEEN_SYMBOL);
        $children[] = $this->windowFrameBound();
        $children[] = $this->match(MySQLLexer::AND_SYMBOL);
        $children[] = $this->windowFrameBound();

        return new ASTNode('windowFrameBetween', $children);
    }

    public function windowFrameBound()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNBOUNDED_SYMBOL ||
            $token->getType() === MySQLLexer::ULONGLONG_NUMBER ||
            $token->getType() === MySQLLexer::PARAM_MARKER ||
            $token->getType() === MySQLLexer::INTERVAL_SYMBOL) {
            return $this->windowFrameStart();
        } elseif ($token->getType() === MySQLLexer::UNBOUNDED_SYMBOL) {
            $children = [];
            $children[] = $this->match(MySQLLexer::UNBOUNDED_SYMBOL);
            $children[] = $this->match(MySQLLexer::FOLLOWING_SYMBOL);
            return new ASTNode('windowFrameBound', $children);
        } else {
            throw new \Exception('Unexpected token in windowFrameBound: ' . $token->getText());
        }
    }

    public function windowFrameExclusion()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::EXCLUDE_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getText() === 'CURRENT ROW') {
            $children[] = $this->match(MySQLLexer::CURRENT_SYMBOL);
            $children[] = $this->match(MySQLLexer::ROW_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::GROUP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TIES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIES_SYMBOL);
        } elseif ($token->getText() === 'NO OTHERS') {
            $children[] = $this->match(MySQLLexer::NO_SYMBOL);
            $children[] = $this->match(MySQLLexer::OTHERS_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in windowFrameExclusion: ' . $token->getText());
        }

        return new ASTNode('windowFrameExclusion', $children);
    }

    public function withClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RECURSIVE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RECURSIVE_SYMBOL);
        }

        $children[] = $this->commonTableExpression();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->commonTableExpression();
        }

        return new ASTNode('withClause', $children);
    }

    public function commonTableExpression()
    {
        $children = [];

        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->columnInternalRefList();
        }
        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        $children[] = $this->subquery();
        return new ASTNode('commonTableExpression', $children);
    }

    public function groupByClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        $children[] = $this->orderList();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->olapOption();
        }

        return new ASTNode('groupByClause', $children);
    }

    public function olapOption()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROLLUP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROLLUP_SYMBOL);
        } elseif ($this->serverVersion < 80000 &&
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::CUBE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CUBE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in olapOption: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('olapOption', $children);
    }

    public function orderClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ORDER_SYMBOL);
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        $children[] = $this->orderList();

        return new ASTNode('orderClause', $children);
    }

    public function direction()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ASC_SYMBOL) {
            $this->match(MySQLLexer::ASC_SYMBOL);
            return new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ASC_SYMBOL           ));
        } elseif ($token->getType() === MySQLLexer::DESC_SYMBOL) {
            return $this->match(MySQLLexer::DESC_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in direction: ' . $token->getText());
        }
    }

    public function fromClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::FROM_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DUAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DUAL_SYMBOL);
        } else {
            $children[] = $this->tableReferenceList();
        }

        return new ASTNode('fromClause', $children);
    }

    public function tableReferenceList()
    {
        $children = [];

        $children[] = $this->tableReference();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->tableReference();
        }

        return new ASTNode('tableReferenceList', $children);
    }

    public function tableValueConstructor()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::VALUES_SYMBOL);
        $children[] = $this->rowValueExplicit();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->rowValueExplicit();
        }

        return new ASTNode('tableValueConstructor', $children);
    }

    public function explicitTable()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        $children[] = $this->tableRef();
        return new ASTNode('explicitTable', $children);
    }

    public function rowValueExplicit()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ROW_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        if ($this->isExprStart($this->lexer->peekNextToken()) ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->values();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('rowValueExplicit', $children);
    }

    public function selectOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ALL_SYMBOL ||
            $token->getType() === MySQLLexer::DISTINCT_SYMBOL ||
            $token->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL ||
            $token->getType() === MySQLLexer::HIGH_PRIORITY_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_SMALL_RESULT_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_BIG_RESULT_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_BUFFER_RESULT_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_CALC_FOUND_ROWS_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_NO_CACHE_SYMBOL ||
            ($this->serverVersion < 80000 &&
             $token->getType() === MySQLLexer::SQL_CACHE_SYMBOL)) {
            return $this->querySpecOption();
        } elseif ($this->serverVersion >= 50704 && $this->serverVersion < 50708 &&
                  $token->getType() === MySQLLexer::MAX_STATEMENT_TIME_SYMBOL) {
            $children = [];
            $children[] = $this->match(MySQLLexer::MAX_STATEMENT_TIME_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->real_ulong_number();
            return new ASTNode('selectOption', $children);
        } else {
            throw new \Exception('Unexpected token in selectOption: ' . $token->getText());
        }
    }

    public function lockingClauseList()
    {
        $children = [];

        do {
            $children[] = $this->lockingClause();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL);

        return new ASTNode('lockingClauseList', $children);
    }

    public function lockingClause()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->lockStrengh();

            if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::OF_SYMBOL) {
                $children[] = $this->match(MySQLLexer::OF_SYMBOL);
                $children[] = $this->tableAliasRefList();
            }

            if ($this->serverVersion >= 80000 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::SKIP_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::NOWAIT_SYMBOL)) {
                $children[] = $this->lockedRowAction();
            }
        } elseif ($token->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);
            $children[] = $this->match(MySQLLexer::IN_SYMBOL);
            $children[] = $this->match(MySQLLexer::SHARE_SYMBOL);
            $children[] = $this->match(MySQLLexer::MODE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in lockingClalockingClause: ' . $token->getText());
        }

        return new ASTNode('lockingClause', $children);
    }

    public function lockStrengh()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UPDATE_SYMBOL) {
            return $this->match(MySQLLexer::UPDATE_SYMBOL);
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::SHARE_SYMBOL) {
            return $this->match(MySQLLexer::SHARE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in lockStrengh: ' . $token->getText());
        }
    }

    public function lockedRowAction()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SKIP_SYMBOL) {
            $this->match(MySQLLexer::SKIP_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SKIP_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::LOCKED_SYMBOL);
            return new ASTNode('lockedRowAction', $children);
        } elseif ($token->getType() === MySQLLexer::NOWAIT_SYMBOL) {
            return $this->match(MySQLLexer::NOWAIT_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in lockedRowAction: ' . $token->getText());
        }
    }

    public function selectItemList()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::MULT_OPERATOR) {
            $children[] = $this->match(MySQLLexer::MULT_OPERATOR);
        } elseif ($this->isSimpleExprStart($token) ||
                  ($this->serverVersion >= 80000 &&
                   ($token->getType() === MySQLLexer::ROW_NUMBER_SYMBOL ||
                    $token->getType() === MySQLLexer::RANK_SYMBOL ||
                    $token->getType() === MySQLLexer::DENSE_RANK_SYMBOL ||
                    $token->getType() === MySQLLexer::CUME_DIST_SYMBOL ||
                    $token->getType() === MySQLLexer::PERCENT_RANK_SYMBOL)) ||
                  ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::ROW_SYMBOL) ||
                  $token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token) ||
                  $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->selectItem();
        } else {
            throw new \Exception('Unexpected token in selectItemList: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->selectItem();
        }

        return new ASTNode('selectItemList', $children);
    }

    public function selectItem()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if ($token1->getType() === MySQLLexer::IDENTIFIER &&
            $token2->getType() === MySQLLexer::DOT_SYMBOL &&
            $this->lexer->peekNextToken(3)->getType() === MySQLLexer::MULT_OPERATOR) {
            $children[] = $this->tableWild();
        } else {
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->selectAlias();
            }
        }

        return new ASTNode('selectItem', $children);
    }

    public function selectAlias()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        }

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            $children[] = $this->identifier();
        } elseif ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $children[] = $this->textStringLiteral();
        } else {
            throw new \Exception('Unexpected token in selectAlias: ' . $token->getText());
        }

        return new ASTNode('selectAlias', $children);
    }

    public function whereClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WHERE_SYMBOL);
        $children[] = $this->expr();
        return new ASTNode('whereClause', $children);
    }

    public function tableReference()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            $token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL ||
            ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::LATERAL_SYMBOL) ||
            ($this->serverVersion >= 80004 && $token->getType() === MySQLLexer::JSON_TABLE_SYMBOL)) {
            $children[] = $this->tableFactor();
        } elseif ($token->getType() === MySQLLexer::OPEN_CURLY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_CURLY_SYMBOL);
            if ($this->serverVersion < 80017) {
                $children[] = $this->identifier();
            } else {
                $children[] = $this->match(MySQLLexer::OJ_SYMBOL);
            }
            $children[] = $this->escapedTableReference();
            $children[] = $this->match(MySQLLexer::CLOSE_CURLY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in tableReference: ' . $token->getText());
        }
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::JOIN_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::INNER_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::CROSS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::LEFT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::RIGHT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NATURAL_SYMBOL) {
            $children[] = $this->joinedTable();
        }

        return new ASTNode('tableReference', $children);
    }

    public function escapedTableReference()
    {
        $children = [];
        $children[] = $this->tableFactor();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::JOIN_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::INNER_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::CROSS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::LEFT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::RIGHT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NATURAL_SYMBOL) {
            $children[] = $this->joinedTable();
        }

        return new ASTNode('escapedTableReference', $children);
    }

    public function joinedTable()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::NATURAL_SYMBOL) {
            $children[] = $this->naturalJoinType();
            $children[] = $this->tableFactor();
        } elseif ($token->getType() === MySQLLexer::INNER_SYMBOL ||
                  $token->getType() === MySQLLexer::CROSS_SYMBOL ||
                  $token->getType() === MySQLLexer::JOIN_SYMBOL ||
                  $token->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL) {
            $children[] = $this->innerJoinType();
            $children[] = $this->tableReference();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                    $children[] = $this->expr();
                } else {
                    $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                    $children[] = $this->identifierListWithParentheses();
                }
            }
        } elseif ($token->getType() === MySQLLexer::LEFT_SYMBOL ||
                  $token->getType() === MySQLLexer::RIGHT_SYMBOL) {
            $children[] = $this->outerJoinType();
            $children[] = $this->tableReference();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                $children[] = $this->expr();
            } else {
                $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                $children[] = $this->identifierListWithParentheses();
            }
        } else {
            throw new \Exception('Unexpected token in joinedTable: ' . $token->getText());
        }

        return new ASTNode('joinedTable', $children);
    }

    public function naturalJoinType()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::NATURAL_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INNER_SYMBOL ||
            $token->getType() === MySQLLexer::JOIN_SYMBOL) {
            if ($token->getType() === MySQLLexer::INNER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INNER_SYMBOL);
            }

            $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
            return new ASTNode('naturalJoinType', $children);
        } elseif ($token->getType() === MySQLLexer::LEFT_SYMBOL ||
                  $token->getType() === MySQLLexer::RIGHT_SYMBOL) {
            if ($token->getType() === MySQLLexer::LEFT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LEFT_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::RIGHT_SYMBOL);
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OUTER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::OUTER_SYMBOL);
            }

            $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
            return new ASTNode('naturalJoinType', $children);
        } else {
            throw new \Exception('Unexpected token in naturalJoinType: ' . $token->getText());
        }
    }

    public function innerJoinType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::INNER_SYMBOL ||
            $token->getType() === MySQLLexer::CROSS_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INNER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INNER_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::CROSS_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::JOIN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::STRAIGHT_JOIN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STRAIGHT_JOIN_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in innerJoinType: ' . $token->getText());
        }

        return new ASTNode('innerJoinType', $children);
    }

    public function outerJoinType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::LEFT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LEFT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::RIGHT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RIGHT_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in outerJoinType: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OUTER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OUTER_SYMBOL);
        }
        $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);

        return new ASTNode('outerJoinType', $children);
    }

    /**
     * MySQL has a syntax extension where a comma-separated list of table
     * references is allowed as a table reference in itself, for instance
     * 
     *     SELECT * FROM (t1, t2) JOIN t3 ON 1
     * 
     * which is not allowed in standard SQL. The syntax is equivalent to
     * 
     *     SELECT * FROM (t1 CROSS JOIN t2) JOIN t3 ON 1
     * 
     * We call this rule tableReferenceListParens.
     */
    public function tableFactor()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->singleTable();
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::SELECT_SYMBOL) {
            return $this->derivedTable();
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            return $this->tableReferenceListParens();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::LATERAL_SYMBOL) {
            return $this->derivedTable();
        } elseif ($this->serverVersion >= 80004 && $token->getType() === MySQLLexer::JSON_TABLE_SYMBOL) {
            return $this->tableFunction();
        } else {
            throw new \Exception('Unexpected token in tableFactor: ' . $token->getText());
        }
    }

    public function singleTable()
    {
        $children = [];
        $children[] = $this->tableRef();
        if ($this->serverVersion >= 50602 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
            $children[] = $this->usePartition();
        }
        
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeywordsUnambiguous($this->lexer->peekNextToken())) {
            $children[] = $this->tableAlias();
        }
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::USE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::FORCE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->indexHintList();
        }
        return new ASTNode('singleTable', $children);
    }

    public function tableReferenceListParens()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            $token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL ||
            ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::LATERAL_SYMBOL) ||
            ($this->serverVersion >= 80004 && $token->getType() === MySQLLexer::JSON_TABLE_SYMBOL)) {
            $children[] = $this->tableReferenceList();
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->tableReferenceListParens();
        } else {
            throw new \Exception('Unexpected token in tableReferenceListParens: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('tableReferenceListParens', $children);
    }

    public function tableFunction()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::JSON_TABLE_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->expr();
        $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
        $children[] = $this->textStringLiteral();
        $children[] = $this->columnsClause();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->tableAlias();
        }

        return new ASTNode('tableFunction', $children);
    }

    public function columnsClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::COLUMNS_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->jtColumn();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->jtColumn();
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('columnsClause', $children);
    }

    public function jtColumn()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if ($token1->getType() === MySQLLexer::IDENTIFIER &&
            $token2->getType() === MySQLLexer::FOR_SYMBOL &&
            $this->lexer->peekNextToken(3)->getType() === MySQLLexer::ORDINALITY_SYMBOL) {
            $children[] = $this->identifier();
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::ORDINALITY_SYMBOL);
        } elseif (($token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1)) &&
                  ($token2->getType() === MySQLLexer::INT_SYMBOL ||
                   $token2->getType() === MySQLLexer::TINYINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::SMALLINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::MEDIUMINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::BIGINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::REAL_SYMBOL ||
                   $token2->getType() === MySQLLexer::DOUBLE_SYMBOL ||
                   $token2->getType() === MySQLLexer::FLOAT_SYMBOL ||
                   $token2->getType() === MySQLLexer::DECIMAL_SYMBOL ||
                   $token2->getType() === MySQLLexer::NUMERIC_SYMBOL ||
                   $token2->getType() === MySQLLexer::FIXED_SYMBOL ||
                   $token2->getType() === MySQLLexer::BIT_SYMBOL ||
                   $token2->getType() === MySQLLexer::BOOL_SYMBOL ||
                   $token2->getType() === MySQLLexer::BOOLEAN_SYMBOL ||
                   $token2->getType() === MySQLLexer::CHAR_SYMBOL ||
                   $token2->getType() === MySQLLexer::NCHAR_SYMBOL ||
                   $token2->getType() === MySQLLexer::NATIONAL_SYMBOL ||
                   $token2->getType() === MySQLLexer::BINARY_SYMBOL ||
                   $token2->getType() === MySQLLexer::VARBINARY_SYMBOL ||
                   $token2->getType() === MySQLLexer::TINYBLOB_SYMBOL ||
                   $token2->getType() === MySQLLexer::BLOB_SYMBOL ||
                   $token2->getType() === MySQLLexer::MEDIUMBLOB_SYMBOL ||
                   $token2->getType() === MySQLLexer::LONGBLOB_SYMBOL ||
                   $token2->getType() === MySQLLexer::TINYTEXT_SYMBOL ||
                   $token2->getType() === MySQLLexer::TEXT_SYMBOL ||
                   $token2->getType() === MySQLLexer::MEDIUMTEXT_SYMBOL ||
                   $token2->getType() === MySQLLexer::LONGTEXT_SYMBOL ||
                   $token2->getType() === MySQLLexer::ENUM_SYMBOL ||
                   $token2->getType() === MySQLLexer::SET_SYMBOL ||
                   ($this->serverVersion >= 50708 &&
                    $token2->getType() === MySQLLexer::JSON_SYMBOL) ||
                   $token2->getType() === MySQLLexer::GEOMETRY_SYMBOL ||
                   $token2->getType() === MySQLLexer::POINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::LINESTRING_SYMBOL ||
                   $token2->getType() === MySQLLexer::POLYGON_SYMBOL ||
                   $token2->getType() === MySQLLexer::GEOMETRYCOLLECTION_SYMBOL ||
                   $token2->getType() === MySQLLexer::MULTIPOINT_SYMBOL ||
                   $token2->getType() === MySQLLexer::MULTILINESTRING_SYMBOL ||
                   $token2->getType() === MySQLLexer::MULTIPOLYGON_SYMBOL ||
                   $token2->getType() === MySQLLexer::YEAR_SYMBOL ||
                   $token2->getType() === MySQLLexer::DATE_SYMBOL ||
                   $token2->getType() === MySQLLexer::TIME_SYMBOL ||
                   $token2->getType() === MySQLLexer::TIMESTAMP_SYMBOL ||
                   $token2->getType() === MySQLLexer::DATETIME_SYMBOL)) {
            $children[] = $this->identifier();
            $children[] = $this->dataType();
            if ($this->serverVersion >= 80014 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL) {
                $children[] = $this->collate();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXISTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EXISTS_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::PATH_SYMBOL);
            $children[] = $this->textStringLiteral();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ERROR_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NULL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::EMPTY_SYMBOL) {
                $children[] = $this->onEmptyOrError();
            }
        } elseif ($token1->getType() === MySQLLexer::NESTED_SYMBOL &&
                  $token2->getType() === MySQLLexer::PATH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NESTED_SYMBOL);
            $children[] = $this->match(MySQLLexer::PATH_SYMBOL);
            $children[] = $this->textStringLiteral();
            $children[] = $this->columnsClause();
        } else {
            throw new \Exception('Unexpected token in jtColumn: ' . $token1->getText());
        }

        return new ASTNode('jtColumn', $children);
    }

    public function onEmptyOrError()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::ERROR_SYMBOL ||
            $token->getType() === MySQLLexer::NULL_SYMBOL ||
            $token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->onEmpty();
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::ERROR_SYMBOL ||
                $token->getType() === MySQLLexer::NULL_SYMBOL ||
                $token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->onError();
            }
            return new ASTNode('onEmptyOrError', $children);
        } elseif ($token->getType() === MySQLLexer::ERROR_SYMBOL) {
            $children[] = $this->onError();
            $children[] = $this->onEmpty();
            return new ASTNode('onEmptyOrError', $children);
        } else {
            throw new \Exception('Unexpected token in onEmptyOrError: ' . $token->getText());
        }
    }

    public function onEmpty()
    {
        $children = [];

        $children[] = $this->jtOnResponse();
        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->match(MySQLLexer::EMPTY_SYMBOL);

        return new ASTNode('onEmpty', $children);
    }

    public function onError()
    {
        $children = [];

        $children[] = $this->jtOnResponse();
        $children[] = $this->match(MySQLLexer::ON_SYMBOL);
        $children[] = $this->match(MySQLLexer::ERROR_SYMBOL);

        return new ASTNode('onError', $children);
    }

    public function jtOnResponse()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::ERROR_SYMBOL) {
            return $this->match(MySQLLexer::ERROR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NULL_SYMBOL) {
            return $this->match(MySQLLexer::NULL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children = [];
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = $this->textStringLiteral();
            return new ASTNode('jtOnResponse', $children);
        } else {
            throw new \Exception('Unexpected token in jtOnResponse: ' . $token->getText());
        }
    }

    public function unionOption()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::DISTINCT_SYMBOL:
        case MySQLLexer::ALL_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function tableAlias()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        } elseif ($this->serverVersion < 80017 &&
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        }

        $children[] = $this->identifier();

        return new ASTNode('tableAlias', $children);
    }

    public function indexHintList()
    {
        $children = [];

        $children[] = $this->indexHint();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->indexHint();
        }

        return new ASTNode('indexHintList', $children);
    }

    public function indexHint()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::USE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in indexHint: ' . $this->lexer->peekNextToken()->getText());
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->indexHintClause();
            }
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL) {
                $children[] = $this->indexList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::FORCE_SYMBOL || $token->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->indexHintType();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in indexHint: ' . $this->lexer->peekNextToken()->getText());
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->indexHintClause();
            }
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->indexList();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in indexHint: ' . $token->getText());
        }

        return new ASTNode('indexHint', $children);
    }

    public function indexHintType()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::FORCE_SYMBOL:
        case MySQLLexer::IGNORE_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function indexHintClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::JOIN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
        } elseif ($token->getText() === 'ORDER BY') {
            $children[] = $this->match(MySQLLexer::ORDER_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        } elseif ($token->getText() === 'GROUP BY') {
            $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in indexHintClause: ' . $token->getText());
        }

        return new ASTNode('indexHintClause', $children);
    }

    public function indexList()
    {
        $children = [];

        $children[] = $this->indexListElement();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->indexListElement();
        }

        return new ASTNode('indexList', $children);
    }

    public function indexListElement()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            return $this->identifier();
        } elseif ($token->getType() === MySQLLexer::PRIMARY_SYMBOL) {
            return $this->match(MySQLLexer::PRIMARY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in indexListElement: ' . $token->getText());
        }
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function updateStatement()
    {
        $children = [];

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->withClause();
        }

        $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOW_PRIORITY_SYMBOL);
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
        }

        $children[] = $this->tableReferenceList();
        $children[] = $this->match(MySQLLexer::SET_SYMBOL);
        $children[] = $this->updateList();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
            $children[] = $this->whereClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
            $children[] = $this->orderClause();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
            $children[] = $this->simpleLimitClause();
        }

        return new ASTNode('updateStatement', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function transactionOrLockingStatement()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::START_SYMBOL ||
            $token->getType() === MySQLLexer::COMMIT_SYMBOL) {
            return $this->transactionStatement();
        } elseif ($token->getType() === MySQLLexer::SAVEPOINT_SYMBOL ||
                  $token->getType() === MySQLLexer::ROLLBACK_SYMBOL ||
                  $token->getType() === MySQLLexer::RELEASE_SYMBOL) {
            return $this->savepointStatement();
        } elseif ($token->getType() === MySQLLexer::LOCK_SYMBOL ||
                  $token->getType() === MySQLLexer::UNLOCK_SYMBOL) {
            return $this->lockStatement();
        } elseif ($token->getType() === MySQLLexer::XA_SYMBOL) {
            return $this->xaStatement();
        } else {
            throw new \Exception('Unexpected token in transactionOrLockingStatement: ' . $token->getText());
        }
    }

    // BEGIN WORK is separated from transactional statements as it must not appear as part of a stored program.
    public function beginWork()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::BEGIN_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WORK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::WORK_SYMBOL);
        }

        return new ASTNode('beginWork', $children);
    }

    public function transactionStatement()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::START_SYMBOL) {
            $children[] = $this->match(MySQLLexer::START_SYMBOL);
            $children[] = $this->match(MySQLLexer::TRANSACTION_SYMBOL);

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::READ_SYMBOL) {
                $children[] = $this->transactionCharacteristic();
            }
        } elseif ($token->getType() === MySQLLexer::COMMIT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMIT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WORK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WORK_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AND_SYMBOL) {
                $children[] = $this->match(MySQLLexer::AND_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::NO_SYMBOL);
                }
                $children[] = $this->match(MySQLLexer::CHAIN_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RELEASE_SYMBOL ||
                $this->lexer->peekNextToken()->getText() === 'NO RELEASE') {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::NO_SYMBOL);
                }
                $children[] = $this->match(MySQLLexer::RELEASE_SYMBOL);
            }
        } else {
            throw new \Exception('Unexpected token in transactionStatement: ' . $token->getText());
        }

        return new ASTNode('transactionStatement', $children);
    }

    public function transactionCharacteristic()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::WITH_SYMBOL) {
            $children = [];

            $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
            $children[] = $this->match(MySQLLexer::CONSISTENT_SYMBOL);
            $children[] = $this->match(MySQLLexer::SNAPSHOT_SYMBOL);

            return new ASTNode('transactionCharacteristic', $children);
        } elseif ($this->serverVersion >= 50605 && $token->getType() === MySQLLexer::READ_SYMBOL) {
            $children = [];

            $children[] = $this->match(MySQLLexer::READ_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::WRITE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WRITE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::ONLY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ONLY_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in transactionCharacteristic: ' . $token->getText());
            }

            return new ASTNode('transactionCharacteristic', $children);
        } else {
            throw new \Exception('Unexpected token in transactionCharacteristic: ' . $token->getText());
        }
    }

    public function savepointStatement()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::SAVEPOINT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SAVEPOINT_SYMBOL);
            $children[] = $this->identifier();
        } elseif ($token->getType() === MySQLLexer::ROLLBACK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROLLBACK_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WORK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WORK_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TO_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SAVEPOINT_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::SAVEPOINT_SYMBOL);
                }
                $children[] = $this->identifier();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::AND_SYMBOL) {
                $children[] = $this->match(MySQLLexer::AND_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::NO_SYMBOL);
                }
                $children[] = $this->match(MySQLLexer::CHAIN_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RELEASE_SYMBOL ||
                    $this->lexer->peekNextToken()->getText() === 'NO RELEASE') {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::NO_SYMBOL);
                    }
                    $children[] = $this->match(MySQLLexer::RELEASE_SYMBOL);
                }
            }
        } elseif ($token->getType() === MySQLLexer::RELEASE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RELEASE_SYMBOL);
            $children[] = $this->match(MySQLLexer::SAVEPOINT_SYMBOL);
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in savepointStatement: ' . $token->getText());
        }

        return new ASTNode('savepointStatement', $children);
    }

    public function lockStatement()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);

            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::TABLES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::INSTANCE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INSTANCE_SYMBOL);
                $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                $children[] = $this->match(MySQLLexer::BACKUP_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in lockStatement: ' . $token->getText());
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->lockItem();
                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                   $children[] = $this->lockItem();
                }
            }
        } elseif ($token->getType() === MySQLLexer::UNLOCK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNLOCK_SYMBOL);

            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::TABLES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::INSTANCE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INSTANCE_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in lockStatement: ' . $token->getText());
            }
        } else {
            throw new \Exception('Unexpected token in lockStatement: ' . $token->getText());
        }

        return new ASTNode('lockStatement', $children);
    }

    public function lockItem()
    {
        $children = [];

        $children[] = $this->tableRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->tableAlias();
        }
        $children[] = $this->lockOption();

        return new ASTNode('lockItem', $children);
    }

    public function lockOption()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::READ_SYMBOL ||
            ($this->serverVersion < 50700 && $token->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL)) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOW_PRIORITY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LOW_PRIORITY_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::READ_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL) {
                $this->match(MySQLLexer::LOCAL_SYMBOL);
                $children[] = ASTNode::fromToken($token);
            }
        } elseif ($token->getType() === MySQLLexer::WRITE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::WRITE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in lockOption: ' . $token->getText());
        }

        return new ASTNode('lockOption', $children);
    }

    public function xaStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::XA_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::START_SYMBOL ||
            $token->getType() === MySQLLexer::BEGIN_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::START_SYMBOL) {
                $children[] = $this->match(MySQLLexer::START_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::BEGIN_SYMBOL);
            }
            $children[] = $this->xid();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::JOIN_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::RESUME_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::JOIN_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::JOIN_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::RESUME_SYMBOL);
                }
            }
        } elseif ($token->getType() === MySQLLexer::END_SYMBOL) {
            $children[] = $this->match(MySQLLexer::END_SYMBOL);
            $children[] = $this->xid();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SUSPEND_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SUSPEND_SYMBOL);
                if ($this->lexer->peekNextToken()->getText() === 'FOR MIGRATE') {
                    $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                    $children[] = $this->match(MySQLLexer::MIGRATE_SYMBOL);
                }
            }
        } elseif ($token->getType() === MySQLLexer::PREPARE_SYMBOL) {
            $this->match(MySQLLexer::PREPARE_SYMBOL);
            $children[] = ASTNode::fromToken($token);
            $children[] = $this->xid();
        } elseif ($token->getType() === MySQLLexer::COMMIT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMIT_SYMBOL);
            $children[] = $this->xid();
            if ($this->lexer->peekNextToken()->getText() === 'ONE PHASE') {
                $children[] = $this->match(MySQLLexer::ONE_SYMBOL);
                $this->match(MySQLLexer::PHASE_SYMBOL);
                $children[] = ASTNode::fromToken($token);
            }
        } elseif ($token->getType() === MySQLLexer::ROLLBACK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROLLBACK_SYMBOL);
            $children[] = $this->xid();
        } elseif ($token->getType() === MySQLLexer::RECOVER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RECOVER_SYMBOL);
            $children[] = $this->xaConvert();
        } else {
            throw new \Exception('Unexpected token in xaStatement: ' . $token->getText());
        }

        return new ASTNode('xaStatement', $children);
    }

    public function xaConvert()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->serverVersion >= 50704 && $token->getType() === MySQLLexer::CONVERT_SYMBOL) {
            $this->match(MySQLLexer::CONVERT_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CONVERT_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::XID_SYMBOL);
            return new ASTNode('xaConvert', $children);
        } else {
            return new ASTNode('xaConvert', []);
        }
    }

    public function xid()
    {
        $children = [];

        $children[] = $this->textString();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->textString();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->ulong_number();
            }
        }

        return new ASTNode('xid', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function replicationStatement()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if ($token1->getType() === MySQLLexer::PURGE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PURGE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::MASTER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in replicationStatement: ' . $token1->getText());
            }

            $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TO_SYMBOL);
                $children[] = $this->textLiteral();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::BEFORE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BEFORE_SYMBOL);
                $children[] = $this->expr();
            } else {
                throw new \Exception('Unexpected token in replicationStatement: ' . $this->lexer->peekNextToken()->getText());
            }
        } elseif ($token1->getType() === MySQLLexer::CHANGE_SYMBOL && $token2->getType() === MySQLLexer::MASTER_SYMBOL) {
            return $this->changeMaster();
        } elseif ($token1->getType() === MySQLLexer::RESET_SYMBOL &&
                  ($token2->getType() === MySQLLexer::MASTER_SYMBOL ||
                   ($this->serverVersion < 80000 && $token2->getType() === MySQLLexer::QUERY_SYMBOL) ||
                   $token2->getType() === MySQLLexer::SLAVE_SYMBOL)) {
            return $this->resetOption();
        } elseif ($this->serverVersion > 80000 &&
                  $token1->getType() === MySQLLexer::RESET_SYMBOL &&
                  $token2->getType() === MySQLLexer::PERSIST_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RESET_SYMBOL);
            $children[] = $this->match(MySQLLexer::PERSIST_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IF_SYMBOL) {
                $children[] = $this->ifExists();
                $children[] = $this->identifier();
            }

            return new ASTNode('replicationStatement', $children);
        } elseif (($token1->getType() === MySQLLexer::START_SYMBOL ||
                   $token1->getType() === MySQLLexer::STOP_SYMBOL) &&
                  $token2->getType() === MySQLLexer::SLAVE_SYMBOL) {
            return $this->slave();
        } elseif ($this->serverVersion >= 50700 &&
                  $token1->getType() === MySQLLexer::CHANGE_SYMBOL &&
                  $token2->getType() === MySQLLexer::REPLICATION_SYMBOL) {
            return $this->changeReplication();
        } elseif ($token1->getType() === MySQLLexer::LOAD_SYMBOL &&
                  ($token2->getType() === MySQLLexer::DATA_SYMBOL ||
                   $token2->getType() === MySQLLexer::TABLE_SYMBOL)) {
            return $this->replicationLoad();
        } elseif ($this->serverVersion > 50706 &&
                  ($token1->getType() === MySQLLexer::START_SYMBOL ||
                   $token1->getType() === MySQLLexer::STOP_SYMBOL) &&
                  $token2->getType() === MySQLLexer::GROUP_REPLICATION_SYMBOL) {
            return $this->groupReplication();
        } else {
            throw new \Exception('Unexpected token in replicationStatement: ' . $token1->getText());
        }

        return new ASTNode('replicationStatement', $children);
    }

    public function replicationLoad()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOAD_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DATA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRef();
        } else {
            throw new \Exception('Unexpected token in replicationLoad: ' . $this->lexer->peekNextToken()->getText());
        }

        $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
        $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);

        return new ASTNode('replicationLoad', $children);
    }

    public function changeMaster()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CHANGE_SYMBOL);
        $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);
        $children[] = $this->match(MySQLLexer::TO_SYMBOL);
        $children[] = $this->changeMasterOptions();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->channel();
        }

        return new ASTNode('changeMaster', $children);
    }

    public function changeMasterOptions()
    {
        $children = [];

        $children[] = $this->masterOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->masterOption();
        }

        return new ASTNode('changeMasterOptions', $children);
    }

    public function masterOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::MASTER_HOST_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_HOST_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_USER_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_PASSWORD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_PASSWORD_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_PORT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_PORT_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_CONNECT_RETRY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_CONNECT_RETRY_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_RETRY_COUNT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_RETRY_COUNT_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_DELAY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_DELAY_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CA_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CAPATH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CAPATH_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_TLS_VERSION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_TLS_VERSION_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CERT_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_TLS_CIPHERSUITES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_TLS_CIPHERSUITES_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->masterTlsCiphersuitesDef();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CIPHER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CIPHER_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_KEY_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CRL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CRL_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_CRLPATH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_CRLPATH_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_HEARTBEAT_PERIOD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_HEARTBEAT_PERIOD_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MASTER_LOG_FILE_SYMBOL ||
                  $token->getType() === MySQLLexer::MASTER_LOG_POS_SYMBOL ||
                  $token->getType() === MySQLLexer::RELAY_LOG_FILE_SYMBOL ||
                  $token->getType() === MySQLLexer::RELAY_LOG_POS_SYMBOL) {
            return $this->masterFileDef();
        } elseif ($token->getType() === MySQLLexer::MASTER_PUBLIC_KEY_PATH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_PUBLIC_KEY_PATH_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::GET_MASTER_PUBLIC_KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::GET_MASTER_PUBLIC_KEY_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($this->serverVersion >= 80017 && $token->getType() === MySQLLexer::NETWORK_NAMESPACE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NETWORK_NAMESPACE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($this->serverVersion >= 50602 && $token->getType() === MySQLLexer::MASTER_BIND_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_BIND_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::IGNORE_SERVER_IDS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SERVER_IDS_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->serverIdList();
        } elseif ($this->serverVersion >= 80018 &&
                  $token->getType() === MySQLLexer::MASTER_COMPRESSION_ALGORITHM_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_COMPRESSION_ALGORITHM_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringLiteral();
        } elseif ($this->serverVersion >= 80018 &&
                  $token->getType() === MySQLLexer::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($this->serverVersion >= 50605 &&
                  $token->getType() === MySQLLexer::MASTER_AUTO_POSITION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_AUTO_POSITION_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($this->serverVersion >= 80018 &&
                  $token->getType() === MySQLLexer::PRIVILEGE_CHECKS_USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PRIVILEGE_CHECKS_USER_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->privilegeCheckDef();
        } elseif ($this->serverVersion >= 80019 &&
                  $token->getType() === MySQLLexer::REQUIRE_ROW_FORMAT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REQUIRE_ROW_FORMAT_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } elseif ($this->serverVersion >= 80019 &&
                  $token->getType() === MySQLLexer::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->tablePrimaryKeyCheckDef();
        } else {
            throw new \Exception('Unexpected token in masterOption: ' . $token->getText());
        }

        return new ASTNode('masterOption', $children);
    }

    public function privilegeCheckDef()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
            $token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            return $this->userIdentifierOrText();
        } elseif ($token->getType() === MySQLLexer::NULL_SYMBOL) {
            return $this->match(MySQLLexer::NULL_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in privilegeCheckDef: ' . $token->getText());
        }
    }

    public function tablePrimaryKeyCheckDef()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::STREAM_SYMBOL:
        case MySQLLexer::ON_SYMBOL:
        case MySQLLexer::OFF_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function masterTlsCiphersuitesDef()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::NULL_SYMBOL) {
            return $this->match(MySQLLexer::NULL_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in masterTlsCiphersuitesDef: ' . $token->getText());
        }
    }

    public function masterFileDef()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::MASTER_LOG_FILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_LOG_FILE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::MASTER_LOG_POS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_LOG_POS_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::RELAY_LOG_FILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RELAY_LOG_FILE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textStringNoLinebreak();
        } elseif ($token->getType() === MySQLLexer::RELAY_LOG_POS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RELAY_LOG_POS_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->ulong_number();
        } else {
            throw new \Exception('Unexpected token in masterFileDef: ' . $token->getText());
        }

        return new ASTNode('masterFileDef', $children);
    }

    public function serverIdList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ULONGLONG_NUMBER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LONG_NUMBER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::INT_NUMBER) {
            $children[] = $this->ulong_number();

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->ulong_number();
            }
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('serverIdList', $children);
    }

    public function changeReplication()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CHANGE_SYMBOL);
        $children[] = $this->match(MySQLLexer::REPLICATION_SYMBOL);
        $children[] = $this->match(MySQLLexer::FILTER_SYMBOL);
        $children[] = $this->filterDefinition();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->filterDefinition();
        }
        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->channel();
        }

        return new ASTNode('changeReplication', $children);
    }

    public function filterDefinition()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::REPLICATE_DO_DB_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_DO_DB_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->filterDbList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_IGNORE_DB_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_IGNORE_DB_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->filterDbList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_DO_TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_DO_TABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->filterTableList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_IGNORE_TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_IGNORE_TABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->filterTableList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_WILD_DO_TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_WILD_DO_TABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->filterStringList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_WILD_IGNORE_TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_WILD_IGNORE_TABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->filterStringList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::REPLICATE_REWRITE_DB_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATE_REWRITE_DB_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->filterDbPairList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in filterDefinition: ' . $token->getText());
        }

        return new ASTNode('filterDefinition', $children);
    }

    public function filterDbList()
    {
        $children = [];

        $children[] = $this->schemaRef();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->schemaRef();
        }

        return new ASTNode('filterDbList', $children);
    }

    public function filterTableList()
    {
        $children = [];

        $children[] = $this->filterTableRef();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->filterTableRef();
        }

        return new ASTNode('filterTableList', $children);
    }

    public function filterStringList()
    {
        $children = [];

        $children[] = $this->filterWildDbTableString();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->filterWildDbTableString();
        }

        return new ASTNode('filterStringList', $children);
    }

    public function filterWildDbTableString()
    {
        return $this->textStringNoLinebreak();
    }

    public function filterDbPairList()
    {
        $children = [];

        $children[] = $this->schemaIdentifierPair();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->schemaIdentifierPair();
        }

        return new ASTNode('filterDbPairList', $children);
    }

    public function resetOption()
    {
        $this->match(MySQLLexer::RESET_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::RESET_SYMBOL))];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::MASTER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);

            if ($this->serverVersion >= 80000 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL) {
                $children[] = $this->masterResetOptions();
            }
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::QUERY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::QUERY_SYMBOL);
            $children[] = $this->match(MySQLLexer::CACHE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SLAVE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->channel();
            }
        } else {
            throw new \Exception('Unexpected token in resetOption: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);

            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::MASTER_SYMBOL) {
                $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);

                if ($this->serverVersion >= 80000 &&
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::TO_SYMBOL) {
                    $children[] = $this->masterResetOptions();
                }
            } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::QUERY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::QUERY_SYMBOL);
                $children[] = $this->match(MySQLLexer::CACHE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::SLAVE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->channel();
                }
            } else {
                throw new \Exception('Unexpected token in resetOption: ' . $token->getText());
            }
        }

        return new ASTNode('resetOption', $children);
    }

    public function masterResetOptions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::TO_SYMBOL);
        if ($this->serverVersion >= 80017 && $this->isReal_ulonglong_numberStart($this->lexer->peekNextToken())) {
            $children[] = $this->real_ulonglong_number();
        } elseif ($this->isReal_ulong_numberStart($this->lexer->peekNextToken())) {
            $children[] = $this->real_ulong_number();
        } else {
            throw new \Exception('Unexpected token in masterResetOptions: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('masterResetOptions', $children);
    }

    public function slave()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::START_SYMBOL) {
            $children[] = $this->match(MySQLLexer::START_SYMBOL);
            $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RELAY_THREAD_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_THREAD_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::IO_THREAD_SYMBOL) {
                $children[] = $this->slaveThreadOptions();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNTIL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::UNTIL_SYMBOL);
                $children[] = $this->slaveUntilOptions();
            }
            if ($this->serverVersion >= 50604 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::USER_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::PASSWORD_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_AUTH_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::PLUGIN_DIR_SYMBOL)) {
                $children[] = $this->slaveConnectionOptions();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->channel();
            }
        } elseif ($token->getType() === MySQLLexer::STOP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STOP_SYMBOL);
            $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RELAY_THREAD_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_THREAD_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::IO_THREAD_SYMBOL) {
                $children[] = $this->slaveThreadOptions();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->channel();
            }
        } else {
            throw new \Exception('Unexpected token in slave: ' . $token->getText());
        }

        return new ASTNode('slave', $children);
    }

    public function slaveUntilOptions()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::MASTER_LOG_FILE_SYMBOL ||
            $token->getType() === MySQLLexer::MASTER_LOG_POS_SYMBOL ||
            $token->getType() === MySQLLexer::RELAY_LOG_FILE_SYMBOL ||
            $token->getType() === MySQLLexer::RELAY_LOG_POS_SYMBOL ||
            ($this->serverVersion >= 50606 &&
             ($token->getType() === MySQLLexer::SQL_BEFORE_GTIDS_SYMBOL ||
              $token->getType() === MySQLLexer::SQL_AFTER_GTIDS_SYMBOL ||
              $token->getType() === MySQLLexer::SQL_AFTER_MTS_GAPS_SYMBOL))) {
            if ($token->getType() === MySQLLexer::MASTER_LOG_FILE_SYMBOL ||
                $token->getType() === MySQLLexer::MASTER_LOG_POS_SYMBOL ||
                $token->getType() === MySQLLexer::RELAY_LOG_FILE_SYMBOL ||
                $token->getType() === MySQLLexer::RELAY_LOG_POS_SYMBOL) {
                $children[] = $this->masterFileDef();
            } elseif ($this->serverVersion >= 50606) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_BEFORE_GTIDS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::SQL_BEFORE_GTIDS_SYMBOL);
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_AFTER_GTIDS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::SQL_AFTER_GTIDS_SYMBOL);
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::SQL_AFTER_MTS_GAPS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::SQL_AFTER_MTS_GAPS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in slaveUntilOptions: ' . $this->lexer->peekNextToken()->getText());
                }
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
                $children[] = $this->textString();
            } else {
                throw new \Exception('Unexpected token in slaveUntilOptions: ' . $this->lexer->peekNextToken()->getText());
            }

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->masterFileDef();
            }
        }

        return new ASTNode('slaveUntilOptions', $children);
    }

    public function slaveConnectionOptions()
    {
        $children = [];
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);

        if ($token1->getType() === MySQLLexer::USER_SYMBOL && $token2->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::USER_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textString();
        }

        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        if ($token1->getType() === MySQLLexer::PASSWORD_SYMBOL && $token2->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textString();
        }

        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->       peekNextToken(2);
        if ($token1->getType() === MySQLLexer::DEFAULT_AUTH_SYMBOL &&
            $token2->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::DEFAULT_AUTH_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textString();
        }

        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        if ($token1->getType() === MySQLLexer::PLUGIN_DIR_SYMBOL &&
            $token2->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->match(MySQLLexer::PLUGIN_DIR_SYMBOL);
            $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            $children[] = $this->textString();
        }

        return new ASTNode('slaveConnectionOptions', $children);
    }

    public function slaveThreadOptions()
    {
        $children = [];

        $children[] = $this->slaveThreadOption();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->slaveThreadOption();
        }

        return new ASTNode('slaveThreadOptions', $children);
    }

    public function slaveThreadOption()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::RELAY_THREAD_SYMBOL:
        case MySQLLexer::SQL_THREAD_SYMBOL:
        case MySQLLexer::IO_THREAD_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function groupReplication()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::START_SYMBOL) {
            $this->match(MySQLLexer::START_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::START_SYMBOL)),
            ];
        } elseif ($token->getType() === MySQLLexer::STOP_SYMBOL) {
            $this->match(MySQLLexer::STOP_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::STOP_SYMBOL)),
            ];
        } else {
            throw new \Exception('Unexpected token in groupReplication: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::GROUP_REPLICATION_SYMBOL);

        return new ASTNode('groupReplication', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function preparedStatement()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::PREPARE_SYMBOL) {
            $this->match(MySQLLexer::PREPARE_SYMBOL);
            $children[] = ASTNode::fromToken($token);
            $children[] = $this->identifier();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->textLiteral();
            } elseif ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                      $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
                $children[] = $this->userVariable();
            } else {
                throw new \Exception('Unexpected token in preparedStatement: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::EXECUTE_SYMBOL) {
            return $this->executeStatement();
        } elseif ($token->getType() === MySQLLexer::DEALLOCATE_SYMBOL ||
                  $token->getType() === MySQLLexer::DROP_SYMBOL) {
            if ($token->getType() === MySQLLexer::DEALLOCATE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEALLOCATE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
            }
            $this->match(MySQLLexer::PREPARE_SYMBOL);
            $children[] = ASTNode::fromToken($token);
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in preparedStatement: ' . $token->getText());
        }

        return new ASTNode('preparedStatement', $children);
    }

    public function executeStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::EXECUTE_SYMBOL);
        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USING_SYMBOL);
            $children[] = $this->executeVarList();
        }

        return new ASTNode('executeStatement', $children);
    }

    public function executeVarList()
    {
        $children = [];

        $children[] = $this->userVariable();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->userVariable();
        }

        return new ASTNode('executeVarList', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function cloneStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CLONE_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::LOCAL_SYMBOL) {
            $this->match(MySQLLexer::LOCAL_SYMBOL);
            $children[] = ASTNode::fromToken($token);
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
                $children[] = $this->equal();
            }
            $children[] = $this->textStringLiteral();
        } elseif ($this->serverVersion < 80014 && $token->getType() === MySQLLexer::REMOTE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REMOTE_SYMBOL);

            if ($this->lexer->peekNextToken()->getText() === 'FOR REPLICATION') {
                $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                $children[] = $this->match(MySQLLexer::REPLICATION_SYMBOL);
            }
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::INSTANCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSTANCE_SYMBOL);
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::COLON_SYMBOL);
            $children[] = $this->ulong_number();
            $children[] = $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->textStringLiteral();
            $children[] = $this->dataDirSSL();
        } else {
            throw new \Exception('Unexpected token in cloneStatement: ' . $token->getText());
        }

        return new ASTNode('cloneStatement', $children);
    }

    public function createView()
    {
        $children = [];

        $children[] = $this->viewReplaceOrAlgorithm();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->definerClause();
        }
        if ($this->lexer->peekNextToken()->getText() === 'SQL SECURITY') {
            $children[] = $this->viewSuid();
        }
        $this->match(MySQLLexer::VIEW_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::VIEW_SYMBOL));
        $children[] = $this->viewName();
        $children[] = $this->viewTail();

        return new ASTNode('createView', $children);
    }

    public function viewSuid()
    {
        $children = [];
        $this->match(MySQLLexer::SQL_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SQL_SYMBOL));
        $this->match(MySQLLexer::SECURITY_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SECURITY_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFINER_SYMBOL);
        } else {
            $children[] = $this->match(MySQLLexer::INVOKER_SYMBOL);
        }
        return new ASTNode('viewSuid', $children);
    }

    public function viewAlgorithm()
    {
        $children = [];

        $this->match(MySQLLexer::ALGORITHM_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ALGORITHM_SYMBOL));
        $this->match(MySQLLexer::EQUAL_OPERATOR);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::EQUAL_OPERATOR));
        $children[] = $this->match($this->lexer->peekNextToken()->getType());
        
        return new ASTNode('viewAlgorithm', $children);
    }

    public function createSpatialReference()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getText() === 'OR REPLACE') {
            $this->match(MySQLLexer::OR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OR_SYMBOL));
            $this->match(MySQLLexer::REPLACE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::REPLACE_SYMBOL));
        }
        $this->match(MySQLLexer::SPATIAL_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SPATIAL_SYMBOL));
        $this->match(MySQLLexer::REFERENCE_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::REFERENCE_SYMBOL));
        $this->match(MySQLLexer::SYSTEM_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SYSTEM_SYMBOL));
        if ($this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }
        $children[] = $this->real_ulonglong_number();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::NAME_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFINITION_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::ORGANIZATION_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DESCRIPTION_SYMBOL) {
            $children[] = $this->srsAttribute();
        }

        return new ASTNode('createSpatialReference', $children);
    }

    public function dataDirSSL()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::REQUIRE_SYMBOL) {
            return $this->ssl();
        } elseif ($token->getType() === MySQLLexer::DATA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
                $children[] = $this->equal();
            }

            $children[] = $this->textStringLiteral();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REQUIRE_SYMBOL) {
                $children[] = $this->ssl();
            }

            return new ASTNode('dataDirSSL', $children);
        } else {
            throw new \Exception('Unexpected token in dataDirSSL: ' . $token->getText());
        }
    }

    public function ssl()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REQUIRE_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NO_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::SSL_SYMBOL);

        return new ASTNode('ssl', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    // Note: SET PASSWORD is part of the SET statement.
    public function accountManagementStatement()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->serverVersion >= 50606 && $token->getType() === MySQLLexer::ALTER_SYMBOL) {
            return $this->alterUser();
        } elseif ($token->getType() === MySQLLexer::CREATE_SYMBOL) {
            return $this->createUser();
        } elseif ($token->getType() === MySQLLexer::DROP_SYMBOL) {
            return $this->dropUser();
        } elseif ($token->getType() === MySQLLexer::GRANT_SYMBOL) {
            return $this->grant();
        } elseif ($token->getType() === MySQLLexer::RENAME_SYMBOL) {
            return $this->renameUser();
        } elseif ($token->getType() === MySQLLexer::REVOKE_SYMBOL) {
            return $this->revoke();
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::SET_SYMBOL) {
            return $this->setRole();
        } else {
            throw new \Exception('Unexpected token in accountManagementStatement: ' . $token->getText());
        }
    }

    public function alterUser()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ALTER_SYMBOL);
        $children[] = $this->match(MySQLLexer::USER_SYMBOL);

        if ($this->serverVersion >= 50706 && $this->lexer->peekNextToken()->getType() === MySQLLexer::IF_SYMBOL) {
            $children[] = $this->ifExists();
        }

        $children[] = $this->alterUserTail();
        return new ASTNode('alterUser', $children);
    }

    public function alterUserTail()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if (($token1->getType() === MySQLLexer::IDENTIFIER ||
             $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token1) ||
             $token1->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
             $token1->getType() === MySQLLexer::CURRENT_USER_SYMBOL ||
             $token1->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
             $token1->getType() === MySQLLexer::AT_TEXT_SUFFIX) &&
            ($token2->getType() === MySQLLexer::COMMA_SYMBOL ||
             $token2->getType() === MySQLLexer::IDENTIFIED_SYMBOL ||
             $token2->getType() === MySQLLexer::DISCARD_SYMBOL ||
             $token2->getType() === MySQLLexer::DEFAULT_SYMBOL ||
             ($this->serverVersion >= 80000 && $token2->getType() === MySQLLexer::ACCOUNT_SYMBOL) ||
             ($this->serverVersion >= 80000 && $token2->getType() === MySQLLexer::PASSWORD_SYMBOL) ||
             ($this->serverVersion >= 80019 &&
              $token2->getType() === MySQLLexer::FAILED_LOGIN_ATTEMPTS_SYMBOL) ||
             ($this->serverVersion >= 80019 &&
              $token2->getType() === MySQLLexer::PASSWORD_LOCK_TIME_SYMBOL))) {
            if ($this->serverVersion < 80014) {
                $children[] = $this->createUserList();
            } else {
                $children[] = $this->alterUserList();
            }

            $children[] = $this->alterUserTail();
        } elseif (($token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1) ||
                   $token1->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
                   $token1->getType() === MySQLLexer::CURRENT_USER_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_TEXT_SUFFIX) &&
                  $token2->getType() === MySQLLexer::IDENTIFIED_SYMBOL) {
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::BY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                $children[] = $this->textString();
                if ($this->serverVersion >= 80014 &&
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                    $children[] = $this->replacePassword();
                }
                if ($this->serverVersion >= 80014 &&
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                    $children[] = $this->retainCurrentPassword();
                }
            } elseif ($this->serverVersion >= 80018 && $token->getText() === 'WITH') {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->textOrIdentifier();
                $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                $children[] = $this->match(MySQLLexer::RANDOM_SYMBOL);
                $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                    $children[] = $this->retainCurrentPassword();
                }
            } else {
                throw new \Exception('Unexpected token in alterUserTail: ' . $token->getText());
            }
        } elseif (($token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1) ||
                   $token1->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
                   $token1->getType() === MySQLLexer::CURRENT_USER_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_TEXT_SUFFIX) &&
                  ($this->serverVersion >= 80014 && $token2->getType() === MySQLLexer::DISCARD_SYMBOL)) {
            $children[] = $this->user();
            $children[] = $this->discardOldPassword();
        } elseif (($token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1) ||
                   $token1->getType() === MySQLLexer::SINGLE_QUOTED_TEXT ||
                   $token1->getType() === MySQLLexer::CURRENT_USER_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                   $token1->getType() === MySQLLexer::AT_TEXT_SUFFIX) &&
                  ($this->serverVersion >= 80000 && $token2->getType() === MySQLLexer::DEFAULT_SYMBOL)) {
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::ALL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::NONE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NONE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                      $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                      $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                      $this->isIdentifierKeyword($token) ||
                      $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->roleList();
            } else {
                throw new \Exception('Unexpected token in alterUserTail: ' . $token->getText());
            }
        } elseif ($this->serverVersion >= 80019 &&
                  $token1->getType() === MySQLLexer::FAILED_LOGIN_ATTEMPTS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FAILED_LOGIN_ATTEMPTS_SYMBOL);
            $children[] = $this->real_ulong_number();
        } elseif ($this->serverVersion >= 80019 &&
                  $token1->getType() === MySQLLexer::PASSWORD_LOCK_TIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PASSWORD_LOCK_TIME_SYMBOL);
            if ($this->isReal_ulong_numberStart($this->lexer->peekNextToken())) {
                $children[] = $this->real_ulong_number();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNBOUNDED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::UNBOUNDED_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in alterUserTail: ' . $this->lexer->peekNextToken()->getText());
            }
        } else {
            throw new \Exception('Unexpected token in alterUserTail: ' . $token1->getText());
        }

        return new ASTNode('alterUserTail', $children);
    }

    public function userFunction()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::USER_SYMBOL);
        $children[] = $this->parentheses();
        return new ASTNode('userFunction', $children);
    }

    public function createUser()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CREATE_SYMBOL);
        $children[] = $this->match(MySQLLexer::USER_SYMBOL);

        if ($this->serverVersion >= 50706 && $this->lexer->peekNextToken()->getText() === 'IF NOT EXISTS') {
            $children[] = $this->ifNotExists();
        }

        $children[] = $this->createUserList();

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->defaultRoleClause();
        }

        if ($this->serverVersion >= 50706 &&
            ($this->lexer->peekNextToken()->getType() === MySQLLexer::REQUIRE_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::ACCOUNT_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::PASSWORD_SYMBOL)) {
            $children[] = $this->createUserTail();
        }

        return new ASTNode('createUser', $children);
    }

    public function defaultRoleClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
        $children[] = $this->roleList();
        return new ASTNode('defaultRoleClause', $children);
    }

    public function requireClause()
    {
        $this->match(MySQLLexer::REQUIRE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::REQUIRE_SYMBOL))];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::CIPHER_SYMBOL ||
            $token->getType() === MySQLLexer::ISSUER_SYMBOL ||
            $token->getType() === MySQLLexer::SUBJECT_SYMBOL) {
            $children[] = $this->requireList();
        } elseif ($token->getType() === MySQLLexer::SSL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SSL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::X509_SYMBOL) {
            $children[] = $this->match(MySQLLexer::X509_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NONE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NONE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in requireClause: ' . $token->getText());
        }

        return new ASTNode('requireClause', $children);
    }

    public function connectOptions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);

        do {
            $token = $this->lexer->peekNextToken();
            $temp = [];

            if ($token->getType() === MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL) {
                $this->match(MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL);
                $temp[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL));
                $temp[] = $this->ulong_number();
            } elseif ($token->getType() === MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL) {
                $this->match(MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL);
                $temp[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL));
                $temp[] = $this->ulong_number();
            } elseif ($token->getType() === MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL) {
                $this->match(MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL);
                $temp[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL));
                $temp[] = $this->ulong_number();
            } elseif ($token->getType() === MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL) {
                $this->match(MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL);
                $temp[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL));
                $temp[] = $this->ulong_number();
            } else {
                throw new \Exception('Unexpected token in connectOptions: ' . $token->getText());
            }

            $children[] = new ASTNode('', $temp);
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL);

        return new ASTNode('connectOptions', $children);
    }

    public function accountLockPasswordExpireOptions()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::ACCOUNT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ACCOUNT_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNLOCK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::UNLOCK_SYMBOL);
            } else {
                throw new \Exception(
                    'Unexpected token in accountLockPasswordExpireOptions: ' .
                    $this->lexer->peekNextToken()->getText()
                );
            }
        } elseif ($token->getType() === MySQLLexer::PASSWORD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::EXPIRE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EXPIRE_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTERVAL_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::NEVER_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTERVAL_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
                        $children[] = $this->real_ulong_number();
                        $this->match(MySQLLexer::                        DAY_SYMBOL);
                        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DAY_SYMBOL));
                    } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::NEVER_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::NEVER_SYMBOL);
                    } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
                    } else {
                        throw new \Exception(
                            'Unexpected token in accountLockPasswordExpireOptions: ' .
                            $this->lexer->peekNextToken()->getText()
                        );
                    }
                }
            } elseif ($token->getType() === MySQLLexer::HISTORY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::HISTORY_SYMBOL);
                if ($this->isReal_ulong_numberStart($this->lexer->peekNextToken())) {
                    $children[] = $this->real_ulong_number();
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
                } else {
                    throw new \Exception(
                        'Unexpected token in accountLockPasswordExpireOptions: ' .
                        $this->lexer->peekNextToken()->getText()
                    );
                }
            } elseif ($token->getType() === MySQLLexer::REUSE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REUSE_SYMBOL);
                $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
                if ($this->isReal_ulong_numberStart($this->lexer->peekNextToken())) {
                    $children[] = $this->real_ulong_number();
                    $children[] = $this->match(MySQLLexer::DAY_SYMBOL);
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
                } else {
                    throw new \Exception(
                        'Unexpected token in accountLockPasswordExpireOptions: ' .
                        $this->lexer->peekNextToken()->getText()
                    );
                }
            } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::REQUIRE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REQUIRE_SYMBOL);
                $children[] = $this->match(MySQLLexer::CURRENT_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::OPTIONAL_SYMBOL) {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
                    } else {
                        $children[] = $this->match(MySQLLexer::OPTIONAL_SYMBOL);
                    }
                }
            } else {
                throw new \Exception(
                    'Unexpected token in accountLockPasswordExpireOptions: ' . $token->getText()
                );
            }
        } else {
            throw new \Exception('Unexpected token in accountLockPasswordExpireOptions: ' . $token->getText());
        }

        return new ASTNode('accountLockPasswordExpireOptions', $children);
    }

    public function dropUser()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
        $children[] = $this->match(MySQLLexer::USER_SYMBOL);
        if ($this->serverVersion >= 50706 && $this->lexer->peekNextToken()->getType() === MySQLLexer::IF_SYMBOL) {
            $children[] = $this->ifExists();
        }
        $children[] = $this->userList();

        return new ASTNode('dropUser', $children);
    }

    public function grant()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($this->serverVersion >= 80000 &&
            ($token->getType() === MySQLLexer::IDENTIFIER ||
             $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token) ||
             $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) &&
            $this->lexer->peekNextToken(2)->getType() !== MySQLLexer::ON_SYMBOL) {
            $children[] = $this->roleOrPrivilegesList();
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->userList();
            if ($this->lexer->peekNextToken()->getText() === 'WITH ADMIN OPTION') {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->match(MySQLLexer::ADMIN_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
            }
        } elseif (($token->getType() === MySQLLexer::SELECT_SYMBOL ||
                   $token->getType() === MySQLLexer::INSERT_SYMBOL ||
                   $token->getType() === MySQLLexer::UPDATE_SYMBOL ||
                   $token->getType() === MySQLLexer::REFERENCES_SYMBOL ||
                   ($this->serverVersion > 80000 &&
                    ($token->getType() === MySQLLexer::IDENTIFIER ||
                     $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                     $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                     $this->isIdentifierKeyword($token) ||
                     $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT)) ||
                   $token->getType() === MySQLLexer::DELETE_SYMBOL ||
                   $token->getType() === MySQLLexer::USAGE_SYMBOL ||
                   $token->getType() === MySQLLexer::INDEX_SYMBOL ||
                   $token->getType() === MySQLLexer::DROP_SYMBOL ||
                   $token->getType() === MySQLLexer::EXECUTE_SYMBOL ||
                   $token->getType() === MySQLLexer::RELOAD_SYMBOL ||
                   $token->getType() === MySQLLexer::SHUTDOWN_SYMBOL ||
                   $token->getType() === MySQLLexer::PROCESS_SYMBOL ||
                   $token->getType() === MySQLLexer::FILE_SYMBOL ||
                   $token->getType() === MySQLLexer::PROXY_SYMBOL ||
                   $token->getType() === MySQLLexer::SUPER_SYMBOL ||
                   $token->getType() === MySQLLexer::EVENT_SYMBOL ||
                   $token->getType() === MySQLLexer::TRIGGER_SYMBOL ||
                   $token->getType() === MySQLLexer::CREATE_SYMBOL ||
                   ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::FUNCTION_SYMBOL) ||
                   $token->getType() === MySQLLexer::LOCK_SYMBOL ||
                   $token->getType() === MySQLLexer::REPLICATION_SYMBOL ||
                   $token->getText() === 'SHOW DATABASES' ||
                   $token->getText() === 'SHOW VIEW' ||
                   ($this->serverVersion > 80000 &&
                    ($token->getType() === MySQLLexer::CREATE_SYMBOL || $token->getType() === MySQLLexer::DROP_SYMBOL)) ||
                   $token->getType() === MySQLLexer::GRANT_SYMBOL ||
                   $token->getType() === MySQLLexer::ALL_SYMBOL) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::ON_SYMBOL) {
            if ($token->getType() === MySQLLexer::ALL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRIVILEGES_SYMBOL) {
                    $this->match(MySQLLexer::PRIVILEGES_SYMBOL);
                    $children[] = ASTNode::fromToken($token);
                }
            } else {
                $children[] = $this->roleOrPrivilegesList();
            }
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                $children[] = $this->aclType();
            }
            $children[] = $this->grantIdentifier();
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->grantTargetList();
            if ($this->serverVersion < 80011 && $this->lexer->peekNextToken()->getType() === MySQLLexer::REQUIRE_SYMBOL) {
                $children[] = $this->versionedRequireClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
                $children[] = $this->grantOptions();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                $children[] = $this->grantAs();
            }
        } elseif ($token->getType() === MySQLLexer::PROXY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PROXY_SYMBOL);
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->grantTargetList();
            if ($this->lexer->peekNextToken()->getText() === 'WITH GRANT OPTION') {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
            }
        } else {
            throw new \Exception('Unexpected token in grant: ' . $token->getText());
        }

        return new ASTNode('grant', $children);
    }

    public function grantTargetList()
    {
        if ($this->serverVersion < 80011) {
            return $this->createUserList();
        } else {
            return $this->userList();
        }
    }

    public function grantOptions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
        $token = $this->lexer->peekNextToken(2);
        if ($this->serverVersion < 80011 && $token->getType() !== MySQLLexer::GRANT_SYMBOL) {
            do {
                $children[] = $this->grantOption();
            } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::GRANT_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::GRANT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in grantOptions: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('grantOptions', $children);
    }

    public function exceptRoleList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::EXCEPT_SYMBOL);
        $children[] = $this->roleList();

        return new ASTNode('exceptRoleList', $children);
    }

    public function withRoles()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
        $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $children[] = $this->roleList();
        } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXCEPT_SYMBOL) {
                $children[] = $this->exceptRoleList();
            }
        } elseif ($token->getType() === MySQLLexer::NONE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NONE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in withRoles: ' . $token->getText());
        }

        return new ASTNode('withRoles', $children);
    }

    public function grantAs()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::AS_SYMBOL);
        $children[] = $this->match(MySQLLexer::USER_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->withRoles();
        }

        return new ASTNode('grantAs', $children);
    }

    public function versionedRequireClause()
    {
        return $this->requireClause();
    }

    public function renameUser()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::RENAME_SYMBOL);
        $children[] = $this->match(MySQLLexer::USER_SYMBOL);
        $children[] = $this->user();
        $children[] = $this->match(MySQLLexer::TO_SYMBOL);
        $children[] = $this->user();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::TO_SYMBOL);
            $children[] = $this->user();
        }

        return new ASTNode('renameUser', $children);
    }

    public function revoke()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REVOKE_SYMBOL);

        $token = $this->lexer->peekNextToken();
        if ($this->serverVersion >= 80000 &&
            ($token->getType() === MySQLLexer::IDENTIFIER ||
             $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token) ||
             $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) &&
            $this->lexer->peekNextToken(2)->getType() !== MySQLLexer::ON_SYMBOL) {
            $children[] = $this->roleOrPrivilegesList();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->userList();
        } elseif (($token->getType() === MySQLLexer::SELECT_SYMBOL ||
                   $token->getType() === MySQLLexer::INSERT_SYMBOL ||
                   $token->getType() === MySQLLexer::UPDATE_SYMBOL ||
                   $token->getType() === MySQLLexer::REFERENCES_SYMBOL ||
                   ($this->serverVersion > 80000 &&
                    ($token->getType() === MySQLLexer::IDENTIFIER ||
                     $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                     $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                     $this->isIdentifierKeyword($token) ||
                     $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT)) ||
                   $token->getType() === MySQLLexer::DELETE_SYMBOL ||
                   $token->getType() === MySQLLexer::USAGE_SYMBOL ||
                   $token->getType() === MySQLLexer::INDEX_SYMBOL ||
                   $token->getType() === MySQLLexer::DROP_SYMBOL ||
                   $token->getType() === MySQLLexer::EXECUTE_SYMBOL ||
                   $token->getType() === MySQLLexer::RELOAD_SYMBOL ||
                   $token->getType() === MySQLLexer::SHUTDOWN_SYMBOL ||
                   $token->getType() === MySQLLexer::PROCESS_SYMBOL ||
                   $token->getType() === MySQLLexer::FILE_SYMBOL ||
                   $token->getType() === MySQLLexer::PROXY_SYMBOL ||
                   $token->getType() === MySQLLexer::SUPER_SYMBOL ||
                   $token->getType() === MySQLLexer::EVENT_SYMBOL ||
                   $token->getType() === MySQLLexer::TRIGGER_SYMBOL ||
                   $token->getType() === MySQLLexer::CREATE_SYMBOL ||
                   ($this->serverVersion < 80000 &&
                    $token->getType() === MySQLLexer::FUNCTION_SYMBOL) ||
                   $token->getType() === MySQLLexer::LOCK_SYMBOL ||
                   $token->getType() === MySQLLexer::REPLICATION_SYMBOL ||
                   $token->getText() === 'GRANT OPTION' ||
                   $token->getText() === 'SHOW DATABASES' ||
                   $token->getText() === 'SHOW VIEW' ||
                   ($this->serverVersion > 80000 &&
                    ($token->getType() === MySQLLexer::CREATE_SYMBOL ||
                     $token->getType() === MySQLLexer::DROP_SYMBOL)) ||
                   $token->getType() === MySQLLexer::ALL_SYMBOL) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::ON_SYMBOL) {
            $children[] = $this->roleOrPrivilegesList();
            $children[] = $this->onTypeTo();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->userList();
        } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRIVILEGES_SYMBOL) {
                $this->match(MySQLLexer::PRIVILEGES_SYMBOL);
                $children[] = ASTNode::fromToken($token);
            }

            if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                    $children[] = $this->aclType();
                }
                $children[] = $this->grantIdentifier();
            } elseif ($this->lexer->peekNextToken()->getText() === 'COMMA, GRANT OPTION') {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
                $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
                $children[] = $this->userList();
            }
        } elseif ($token->getType() === MySQLLexer::PROXY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PROXY_SYMBOL);
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->user();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->userList();
        } else {
            throw new \Exception('Unexpected token in revoke: ' . $token->getText());
        }

        return new ASTNode('revoke', $children);
    }

    public function onTypeTo()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];
        if ($token1->getType() === MySQLLexer::ON_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                $children[] = $this->aclType();
            }
            $children[] = $this->grantIdentifier();
            return new ASTNode('onTypeTo', $children);
        } elseif ($this->serverVersion >= 80000 &&
                  ($token1->getType() === MySQLLexer::TABLE_SYMBOL ||
                   $token1->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                   $token1->getType() === MySQLLexer::PROCEDURE_SYMBOL ||
                   $token1->getType() === MySQLLexer::MULT_OPERATOR ||
                   $token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1) ||
                   $token1->getType() === MySQLLexer::DOT_SYMBOL)) {
            if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::ON_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                $children[] = $this->aclType();
            }
            $children[] = $this->grantIdentifier();
            return new ASTNode('onTypeTo', $children);
        } elseif ($this->serverVersion < 80000) {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                $children[] = $this->aclType();
            }
            $children[] = $this->grantIdentifier();
            return new ASTNode('onTypeTo', $children);
        } else {
            throw new \Exception('Unexpected token in onTypeTo: ' . $token1->getText());
        }
    }

    public function roleOrPrivilegesList()
    {
        $children = [];

        $children[] = $this->roleOrPrivilege();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->roleOrPrivilege();
        }

        return new ASTNode('roleOrPrivilegesList', $children);
    }

    public function roleOrPrivilege()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if ($this->serverVersion > 80000 &&
            ($token1->getType() === MySQLLexer::IDENTIFIER ||
             $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token1) ||
             $token1->getType() === MySQLLexer::SINGLE_QUOTED_TEXT)) {
            $children[] = $this->roleIdentifierOrText();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->columnInternalRefList();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_TEXT_SUFFIX ||
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
                    $children[] = $this->match(MySQLLexer::AT_TEXT_SUFFIX);
                } else {
                    $children[] = $this->match(MySQLLexer::AT_SIGN_SYMBOL);
                    $children[] = $this->textOrIdentifier();
                }
            }

            return new ASTNode('roleOrPrivilege', $children);
        } elseif (($token1->getType() === MySQLLexer::SELECT_SYMBOL ||
                   $token1->getType() === MySQLLexer::INSERT_SYMBOL ||
                   $token1->getType() === MySQLLexer::UPDATE_SYMBOL ||
                   $token1->getType() === MySQLLexer::REFERENCES_SYMBOL) &&
                  $token2->getType() !== MySQLLexer::ON_SYMBOL) {
            if ($token1->getType() === MySQLLexer::SELECT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SELECT_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::INSERT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INSERT_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::UPDATE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::REFERENCES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REFERENCES_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in roleOrPrivilege: ' . $token1->getText());
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children               = $this->columnInternalRefList();
            }

            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::DELETE_SYMBOL ||
                  $token1->getType() === MySQLLexer::USAGE_SYMBOL ||
                  $token1->getType() === MySQLLexer::INDEX_SYMBOL ||
                  $token1->getType() === MySQLLexer::DROP_SYMBOL ||
                  $token1->getType() === MySQLLexer::EXECUTE_SYMBOL ||
                  $token1->getType() === MySQLLexer::RELOAD_SYMBOL ||
                  $token1->getType() === MySQLLexer::SHUTDOWN_SYMBOL ||
                  $token1->getType() === MySQLLexer::PROCESS_SYMBOL ||
                  $token1->getType() === MySQLLexer::FILE_SYMBOL ||
                  $token1->getType() === MySQLLexer::PROXY_SYMBOL ||
                  $token1->getType() === MySQLLexer::SUPER_SYMBOL ||
                  $token1->getType() === MySQLLexer::EVENT_SYMBOL ||
                  $token1->getType() === MySQLLexer::TRIGGER_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        } elseif ($token1->getText() === 'GRANT OPTION') {
            $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getText() === 'SHOW DATABASES') {
            $children[] = $this->match(MySQLLexer::SHOW_SYMBOL);
            $children[] = $this->match(MySQLLexer::DATABASES_SYMBOL);
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::CREATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CREATE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::TEMPORARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TEMPORARY_SYMBOL);
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROUTINE_SYMBOL ||
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::USER_SYMBOL ||
                      $this->lexer->peekNextToken()->getType() === MySQLLexer::VIEW_SYMBOL) {
                $token = $this->lexer->peekNextToken();
                if ($token->getType() === MySQLLexer::ROUTINE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ROUTINE_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::USER_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::USER_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::VIEW_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::VIEW_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in roleOrPrivilege: ' . $token->getText());
                }
            }
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::LOCK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::REPLICATION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPLICATION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CLIENT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CLIENT_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::SLAVE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in roleOrPrivilege: ' . $this->lexer->peekNextToken()->getText());
            }
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::SHOW_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SHOW_SYMBOL);
            $children[] = $this->match(MySQLLexer::VIEW_SYMBOL);
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($token1->getType() === MySQLLexer::ALTER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ALTER_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROUTINE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ROUTINE_SYMBOL);
            }
            return new ASTNode('roleOrPrivilege', $children);
        } elseif ($this->serverVersion > 80000 &&
                  ($token1->getType() === MySQLLexer::CREATE_SYMBOL ||
                   $token1->getType() === MySQLLexer::DROP_SYMBOL)) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CREATE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CREATE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
            return new ASTNode('roleOrPrivilege', $children);
        } else {
            throw new \Exception('Unexpected token in roleOrPrivilege: ' . $token1->getText());
        }
    }

    public function grantIdentifier()
    {
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $children = [];

        if ($token1->getType() === MySQLLexer::MULT_OPERATOR) {
            $children[] = $this->match(MySQLLexer::MULT_OPERATOR);

            if ($token2->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
                $children[] = $this->match(MySQLLexer::MULT_OPERATOR);
            }

            return new ASTNode('grantIdentifier', $children);
        } elseif ($token1->getType() === MySQLLexer::IDENTIFIER ||
                  $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token1)) {
            if ($this->serverVersion >= 80017 && $token2->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->schemaRef();
                $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
                $children[] = $this->tableRef();
            } else {
                $children[] = $this->schemaRef();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
                    $children[] = $this->match(MySQLLexer::MULT_OPERATOR);
                }
            }

            return new ASTNode('grantIdentifier', $children);
        } else {
            throw new \Exception('Unexpected token in grantIdentifier: ' . $token1->getText());
        }
    }

    public function requireList()
    {
        $children = [];

        $children[] = $this->requireListElement();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::AND_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AND_SYMBOL);
            $children[] = $this->requireListElement();
        }

        return new ASTNode('requireList', $children);
    }

    public function requireListElement()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::CIPHER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CIPHER_SYMBOL);
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::ISSUER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ISSUER_SYMBOL);
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::SUBJECT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SUBJECT_SYMBOL);
            $children[] = $this->textString();
        } else {
            throw new \Exception('Unexpected token in requireListElement: ' . $token->getText());
        }
        return new ASTNode('requireListElement', $children);
    }

    public function grantOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getText() === 'GRANT OPTION') {
            $children[] = $this->match(MySQLLexer::GRANT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPTION_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL);
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL);
            $children[] = $this->ulong_number();
        } else {
            throw new \Exception('Unexpected token in grantOption: ' . $token->getText());
        }

        return new ASTNode('grantOption', $children);
    }

    public function setRole()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SET_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::IDENTIFIER ||
                $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($token) ||
                $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                $children[] = $this->roleList();
            } elseif ($token->getType() === MySQLLexer::NONE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NONE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in setRole: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::ROLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if (($token->getType() === MySQLLexer::IDENTIFIER ||
                 $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                 $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                 $this->isIdentifierKeyword($token) ||
                 $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) &&
                $this->lexer->peekNextToken(2)->getType() !== MySQLLexer::TO_SYMBOL) {
                $children[] = $this->roleList();
            } elseif ($token->getType() === MySQLLexer::NONE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NONE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ALL_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXCEPT_SYMBOL) {
                    $children[] = $this->exceptRoleList();
                }
            } else {
                throw new \Exception('Unexpected token in setRole: ' . $token->getText());
            }
        } else {
            throw new \Exception('Unexpected token in setRole: ' . $token->getText());
        }

        return new ASTNode('setRole', $children);
    }

    public function roleList()
    {
        $children = [];

        $children[] = $this->role();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->role();
        }

        return new ASTNode('roleList', $children);
    }

    public function role()
    {
        $children = [];

        $children[] = $this->roleIdentifierOrText();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AT_SIGN_SYMBOL);
            $children[] = $this->textOrIdentifier();
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->match(MySQLLexer::AT_TEXT_SUFFIX);
        }

        return new ASTNode('role', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function tableAdministrationStatement()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::ANALYZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ANALYZE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRefList();
            if ($this->serverVersion >= 80000 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::UPDATE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DROP_SYMBOL)) {
                $children[] = $this->histogram();
            }
        } elseif ($token->getType() === MySQLLexer::CHECK_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRefList();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::FAST_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::MEDIUM_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::CHANGED_SYMBOL) {
                $children[] = $this->checkOption();
            }
        } elseif ($token->getType() === MySQLLexer::CHECKSUM_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHECKSUM_SYMBOL);
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRefList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::QUICK_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::EXTENDED_SYMBOL);
                }
            }
        } elseif ($token->getType() === MySQLLexer::OPTIMIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPTIMIZE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRefList();
        } elseif ($token->getType() === MySQLLexer::REPAIR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPAIR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
            $children[] = $this->tableRefList();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::QUICK_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::USE_FRM_SYMBOL) {
                $children[] = $this->repairType();
            }
        } else {
            throw new \Exception('Unexpected token in tableAdministrationStatement: ' . $token->getText());
        }

        return new ASTNode('tableAdministrationStatement', $children);
    }

    public function histogram()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::UPDATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
            $children[] = $this->match(MySQLLexer::HISTOGRAM_SYMBOL);
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->identifierList();
            if ($this->lexer->peekNextToken()->getText() === 'WITH') {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->match(MySQLLexer::INT_NUMBER);
                $children[] = $this->match(MySQLLexer::BUCKETS_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::DROP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
            $children[] = $this->match(MySQLLexer::HISTOGRAM_SYMBOL);
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->identifierList();
        } else {
            throw new \Exception('Unexpected token in histogram: ' . $token->getText());
        }

        return new ASTNode('histogram', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function installUninstallStatment()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::INSTALL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSTALL_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::PLUGIN_SYMBOL) {
                $this->match(MySQLLexer::PLUGIN_SYMBOL);
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->identifier();
                $children[] = $this->match(MySQLLexer::SONAME_SYMBOL);
                $children[] = $this->textStringLiteral();
            } elseif ($token->getType() === MySQLLexer::COMPONENT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMPONENT_SYMBOL);
                $children[] = $this->textStringLiteralList();
            } else {
                throw new \Exception('Unexpected token in installUninstallStatment: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::UNINSTALL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNINSTALL_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::PLUGIN_SYMBOL) {
                $this->match(MySQLLexer::PLUGIN_SYMBOL);
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->pluginRef();
            } elseif ($token->getType() === MySQLLexer::COMPONENT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMPONENT_SYMBOL);
                $children[] = $this->componentRef();
                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                    $children[] = $this->componentRef();
                }
            } else {
                throw new \Exception('Unexpected token in installUninstallStatment: ' . $token->getText());
            }
        } else {
            throw new \Exception('Unexpected token in installUninstallStatment: ' . $token->getText());
        }

        return new ASTNode('installUninstallStatment', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function setStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SET_SYMBOL);
        $children[] = $this->startOptionValueList();

        return new ASTNode('setStatement', $children);
    }

    public function startOptionValueList()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::TRANSACTION_SYMBOL) {
            $this->match(MySQLLexer::TRANSACTION_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TRANSACTION_SYMBOL));
            $children[] = $this->transactionCharacteristics();
        } elseif ($token->getType() === MySQLLexer::PASSWORD_SYMBOL) {
            $this->match(MySQLLexer::PASSWORD_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PASSWORD_SYMBOL));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $this->match(MySQLLexer::FOR_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::FOR_SYMBOL));
                $children[] = $this->user();
            }
            $children[] = $this->equal();
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $token = $this->lexer->peekNextToken();
            if ($this->serverVersion < 80014 &&
                ($token->getType() === MySQLLexer::OLD_PASSWORD_SYMBOL ||
                 $token->getType() === MySQLLexer::PASSWORD_SYMBOL)) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OLD_PASSWORD_SYMBOL) {
                    $this->match(MySQLLexer::OLD_PASSWORD_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OLD_PASSWORD_SYMBOL));
                } else {
                    $this->match(MySQLLexer::PASSWORD_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PASSWORD_SYMBOL));
                }
                $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
                $children[] = $this->textString();
                $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
            } elseif ($this->isTextLiteralStart($token)) {
                $children[] = $this->textString();
                if ($this->serverVersion >= 80014 && $this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                    $children[] = $this->replacePassword();
                }
                if ($this->serverVersion >= 80014 && $this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                    $children[] = $this->retainCurrentPassword();
                }
            } elseif ($this->serverVersion >= 80018 && $token->getType() === MySQLLexer::TO_SYMBOL) {
                $this->match(MySQLLexer::TO_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TO_SYMBOL));
                $this->match(MySQLLexer::RANDOM_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::RANDOM_SYMBOL));
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                    $children[] = $this->replacePassword();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                    $children[] = $this->retainCurrentPassword();
                }
            } else {
                throw new \Exception('Unexpected token in startOptionValueList: ' . $token->getText());
            }
        } elseif ($this->isOptionValueStart($this->lexer->peekNextToken())) {
            $children[] = $this->optionValue();
            $children[] = $this->optionValueListContinued();
        } else {
            throw new \Exception('Unexpected token in startOptionValueList: ' . $token->getText());
        }

        return new ASTNode('startOptionValueList', $children);
    }

    private function isOptionValueStart($token)
    {
        return ($token->getType() === MySQLLexer::GLOBAL_SYMBOL ||
                $token->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $token->getType() === MySQLLexer::SESSION_SYMBOL ||
                ($this->serverVersion >= 80000 &&
                 ($token->getType() === MySQLLexer::PERSIST_SYMBOL ||
                  $token->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL))) ||
               $this->isOptionValueNoOptionTypeStart($token);
    }

    private function isOptionValueNoOptionTypeStart($token)
    {
        return $token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
               $token->getType() === MySQLLexer::AT_TEXT_SUFFIX ||
               $token->getType() === MySQLLexer::AT_AT_SIGN_SYMBOL ||
               $this->isInternalVariableNameStart($token) ||
               $token->getType() === MySQLLexer::CHARSET_SYMBOL ||
               $token->getType() === MySQLLexer::CHAR_SYMBOL ||
               $token->getType() === MySQLLexer::NAMES_SYMBOL;
    }

    private function isInternalVariableNameStart($token)
    {
        return $token->getType() === MySQLLexer::IDENTIFIER ||
               $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
               $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
               $this->isIdentifierKeyword($token) ||
               $token->getType() === MySQLLexer::DOT_SYMBOL ||
               $token->getType() === MySQLLexer::DEFAULT_SYMBOL;
    }

    public function transactionCharacteristics()
    {
        $children = [];
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);

        if ($token1->getType() === MySQLLexer::READ_SYMBOL &&
            ($token2->getType() === MySQLLexer::WRITE_SYMBOL || $token2->getType() === MySQLLexer::ONLY_SYMBOL)) {
            $children[] = $this->transactionAccessMode();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ISOLATION_SYMBOL) {
                $children[] = $this->isolationLevel();
            }
        } elseif ($token1->getType() === MySQLLexer::ISOLATION_SYMBOL) {
            $children[] = $this->isolationLevel();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->transactionAccessMode();
            }
        } else {
            throw new \Exception('Unexpected token in transactionCharacteristics: ' . $token1->getText());
        }

        return new ASTNode('transactionCharacteristics', $children);
    }

    public function transactionAccessMode()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::READ_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WRITE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::WRITE_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::ONLY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ONLY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in transactionAccessMode: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('transactionAccessMode', $children);
    }

    public function isolationLevel()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ISOLATION_SYMBOL);
        $children[] = $this->match(MySQLLexer::LEVEL_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::REPEATABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REPEATABLE_SYMBOL);
            $children[] = $this->match(MySQLLexer::READ_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::READ_SYMBOL) {
            $children[] = $this->match(MySQLLexer::READ_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMITTED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMITTED_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNCOMMITTED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::UNCOMMITTED_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in isolationLevel: ' . $this->lexer->peekNextToken()->getText());
            }
        } elseif ($token->getType() === MySQLLexer::SERIALIZABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SERIALIZABLE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in isolationLevel: ' . $token->getText());
        }

        return new ASTNode('isolationLevel', $children);
    }

    public function optionValueListContinued()
    {
        $children = [];
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->optionValue();
        }
        return new ASTNode('optionValueListContinued', $children);
    }

    public function optionValueNoOptionType()
    {
        $children = [];
        $token1 = $this->lexer->peekNextToken();
        if (($token1->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
             $token1->getType() === MySQLLexer::AT_TEXT_SUFFIX)) {
            $children[] = $this->userVariable();
            $children[] = $this->equal();
            $children[] = $this->expr();
        } elseif ($token1->getType() === MySQLLexer::NAMES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NAMES_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::EQUAL_OPERATOR ||
                $token->getType() === MySQLLexer::ASSIGN_OPERATOR) {
                $children[] = $this->equal();
                $children[] = $this->expr();
            } elseif ($this->isCharsetName($token)) {
                $children[] = $this->charsetName();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL) {
                    $children[] = $this->collate();
                }
            } elseif ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in optionValueNoOptionType: ' . $token->getText());
            }
        } elseif ($token1->getType() === MySQLLexer::CHARSET_SYMBOL || $token1->getType() === MySQLLexer::CHAR_SYMBOL) {
            $children[] = $this->charsetClause();
        } elseif (($token1->getType() === MySQLLexer::AT_AT_SIGN_SYMBOL ||
                   $token1->getType() === MySQLLexer::IDENTIFIER ||
                   $token1->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token1->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token1) ||
                   $token1->getType() === MySQLLexer::DOT_SYMBOL ||
                   $token1->getType() === MySQLLexer::DEFAULT_SYMBOL)) {
            $children[] = $this->setSystemVariable();
            $children[] = $this->equal();
            $children[] = $this->setExprOrDefault();
        }  else {
            $children[] = $this->internalVariableName();
            $children[] = $this->equal();
            $children[] = $this->setExprOrDefault();
        }

        return new ASTNode('optionValueNoOptionType', $children);
    }

    public function optionValue()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::GLOBAL_SYMBOL ||
            $token->getType() === MySQLLexer::LOCAL_SYMBOL ||
            $token->getType() === MySQLLexer::SESSION_SYMBOL ||
            ($this->serverVersion >= 80000 &&
             ($token->getType() === MySQLLexer::PERSIST_SYMBOL ||
              $token->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL))) {
            $children = [];
            $children[] = $this->optionType();
            $children[] = $this->internalVariableName();
            $children[] = $this->equal();
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $children[] = $this->setExprOrDefault();
            return new ASTNode('optionValue', $children);
        } else {
            return $this->optionValueNoOptionType();
        }
    }

    public function setSystemVariable()
    {
        $children = [];

        if($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AT_SIGN_SYMBOL);
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::GLOBAL_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::SESSION_SYMBOL ||
            ($this->serverVersion >= 80000 &&
             ($this->lexer->peekNextToken()->getType() === MySQLLexer::PERSIST_SYMBOL ||
              $this->lexer->peekNextToken()->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL))) {
            $children[] = $this->setVarIdentType();
        }

        $children[] = $this->internalVariableName();
        return new ASTNode('setSystemVariable', $children);
    }

    public function startOptionValueListFollowingOptionType()
    {
        $children = [];

        $children[] = $this->optionValueFollowingOptionType();
        $children[] = $this->optionValueListContinued();

        return new ASTNode('startOptionValueListFollowingOptionType', $children);
    }

    public function optionValueFollowingOptionType()
    {
        $children = [];

        $children[] = $this->internalVariableName();
        $children[] = $this->equal();
        $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        $children[] = $this->setExprOrDefault();
        return new ASTNode('optionValueFollowingOptionType', $children);
    }

    public function setExprOrDefault()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isBoolPriStart($token)) {
            return $this->expr();
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                  $token->getType() === MySQLLexer::ON_SYMBOL ||
                  $token->getType() === MySQLLexer::ALL_SYMBOL ||
                  $token->getType() === MySQLLexer::BINARY_SYMBOL ||
                  ($this->serverVersion >= 80000 &&
                   ($token->getType() === MySQLLexer::ROW_SYMBOL || $token->getType() === MySQLLexer::SYSTEM_SYMBOL))) {
            if ($token->getType() === MySQLLexer::ROW_SYMBOL) {
                $this->match(MySQLLexer::ROW_SYMBOL);
                $children = [
                    new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ROW_SYMBOL)),
                ];
                return new ASTNode('setExprOrDefault', $children);
            } elseif ($token->getType() === MySQLLexer::SYSTEM_SYMBOL) {
                $this->match(MySQLLexer::SYSTEM_SYMBOL);
                $children = [
                    new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SYSTEM_SYMBOL)),
                ];
                return new ASTNode('setExprOrDefault', $children);
            } else {
                $this->match($this->lexer->peekNextToken()->getType());
                return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            }
        } else {
            throw new \Exception('Unexpected token in setExprOrDefault: ' . $token->getText());
        }
    }

    //----------------------------------------------------------------------------------------------------------------------
    public function showStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SHOW_SYMBOL);

        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);
        $token3 = $this->lexer->peekNextToken(3);

        
            if ($this->serverVersion < 50700 && $token1->getType() === MySQLLexer::AUTHORS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::AUTHORS_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::BINARY_SYMBOL && $token2->getType() === MySQLLexer::LOGS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
                $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::BINLOG_SYMBOL && $token2->getType() === MySQLLexer::EVENTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINLOG_SYMBOL);
                $children[] = $this->match(MySQLLexer::EVENTS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::IN_SYMBOL);
                    $children[] = $this->textString();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
                    $children[] = $this->ulonglong_number();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->channel();
                }
            } elseif (($token1->getType() === MySQLLexer::CHARSET_SYMBOL || $token1->getType() === MySQLLexer::CHAR_SYMBOL) &&
                    $token2->getType() !== MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->charset();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::COLLATION_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLLATION_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif (
                $token1->getType() === MySQLLexer::COLUMNS_SYMBOL ||
                (
                    $this->isShowCommandType($token1, $token2) &&
                    (
                        $token2->getType() === MySQLLexer::COLUMNS_SYMBOL ||
                        $token3->getType() === MySQLLexer::COLUMNS_SYMBOL 
                    )
                )
            ) {

                if($this->isShowCommandType($token1, $token2)) {
                    $children[] = $this->showCommandType();
                }
                $children[] = $this->match(MySQLLexer::COLUMNS_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::IN_SYMBOL);
                }

                $children[] = $this->tableRef();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::COMPONENT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMPONENT_SYMBOL);
                $children[] = $this->textStringLiteral();
                $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
            } elseif ($this->serverVersion < 50700 && $token1->getType() === MySQLLexer::CONTRIBUTORS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CONTRIBUTORS_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::COUNT_SYMBOL &&
                    $token2->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COUNT_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = $this->match(MySQLLexer::MULT_OPERATOR);
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::WARNINGS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::WARNINGS_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::ERRORS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ERRORS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }
            } elseif ($token1->getType() === MySQLLexer::CREATE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CREATE_SYMBOL);
                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::DATABASE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::DATABASE_SYMBOL);
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IF_SYMBOL) {
                        $children[] = $this->ifNotExists();
                    }
                    $children[] = $this->schemaRef();
                } elseif ($token->getType() === MySQLLexer::EVENT_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::EVENT_SYMBOL);
                    $children[] = $this->eventRef();
                } elseif (
                    (
                        $this->serverVersion < 80000 &&
                        $token->getType() === MySQLLexer::FUNCTION_SYMBOL
                    ) ||
                    (
                        $this->serverVersion >= 80000 &&
                        $token->getType() === MySQLLexer::IDENTIFIER &&
                        strtoupper($token->getText()) === 'FUNCTION'
                    )
                ) {
                    $children[] = ASTNode::fromToken($this->lexer->getNextToken());
                    $children[] = $this->functionRef();
                } elseif ($token->getType() === MySQLLexer::PROCEDURE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
                    $children[] = $this->procedureRef();
                } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
                    $children[] = $this->tableRef();
                } elseif ($token->getType() === MySQLLexer::TRIGGER_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::TRIGGER_SYMBOL);
                    $children[] = $this->triggerRef();
                } elseif ($this->serverVersion >= 50704 && $token->getType() === MySQLLexer::USER_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::USER_SYMBOL);
                    $children[] = $this->user();
                } elseif ($token->getType() === MySQLLexer::VIEW_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::VIEW_SYMBOL);
                    $children[] = $this->viewRef();
                } elseif ($token->getType() === MySQLLexer::ROLE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ROLE_SYMBOL);
                    $children[] = $this->roleRef();
                } else if($token->getType() === MySQLLexer::INDEX_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                    $children[] = $this->indexRef();
                } else if(
                    $this->serverVersion >= 80014 && 
                    $token->getType() === MySQLLexer::TABLESPACE_SYMBOL
                ) {
                    $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
                    $children[] = $this->tablespaceRef();
                } else if($token->getType() === MySQLLexer::SPATIAL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::SPATIAL_SYMBOL);
                    $children[] = $this->match(MySQLLexer::REFERENCE_SYMBOL);
                    $children[] = $this->match(MySQLLexer::SYSTEM_SYMBOL);
                    $children[] = $this->real_ulonglong_number();
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }
            } elseif ($token1->getType() === MySQLLexer::DATABASES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DATABASES_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::ENGINE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);

                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::IDENTIFIER ||
                    $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($token) ||
                    $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
                    $children[] = $this->engineRef();
                } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }

                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::STATUS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::MUTEX_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::MUTEX_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::LOGS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }
            } elseif ($token1->getType() === MySQLLexer::ERRORS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ERRORS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
            } elseif (
                $token1->getType() === MySQLLexer::TRIGGERS_SYMBOL
                || (
                    $token1->getType() === MySQLLexer::FROM_SYMBOL ||
                    $token2->getType() === MySQLLexer::TRIGGERS_SYMBOL
                )
            ) {
                if($this->isShowCommandType($token1, $token2)) {
                    $children[] = $this->showCommandType();
                }
                $children[] = $this->match(MySQLLexer::TRIGGERS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif (
                (
                    $this->isShowCommandType($token1, $token2) && 
                    (
                        $token2->getType() === MySQLLexer::TABLES_SYMBOL ||
                        $token3->getType() === MySQLLexer::TABLES_SYMBOL
                    )
                )
                || $token1->getType() === MySQLLexer::TABLES_SYMBOL
            ) {
                if($this->isShowCommandType($token1, $token2)) {
                    $children[] = $this->showCommandType();
                }
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::EVENTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EVENTS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif (
                (
                    $this->serverVersion >= 80000 &&
                    $token1->getType() === MySQLLexer::EXTENDED_SYMBOL && (
                        $token2->getType() === MySQLLexer::INDEX_SYMBOL ||
                        $token2->getType() === MySQLLexer::INDEXES_SYMBOL ||
                        $token2->getType() === MySQLLexer::KEYS_SYMBOL
                    )
                ) ||
                    $token1->getType() === MySQLLexer::INDEX_SYMBOL ||
                    $token1->getType() === MySQLLexer::INDEXES_SYMBOL ||
                    $token1->getType() === MySQLLexer::KEYS_SYMBOL
            ) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::EXTENDED_SYMBOL);
                }

                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::INDEX_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::INDEXES_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::INDEXES_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::KEYS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEYS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }

                $children[] = $this->fromOrIn();
                $children[] = $this->tableRef();

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->whereClause();
                }
            } elseif (
                ($token1->getType() === MySQLLexer::FULL_SYMBOL &&
                $token2->getType() === MySQLLexer::PROCESSLIST_SYMBOL)
                || $token1->getType() === MySQLLexer::PROCESSLIST_SYMBOL
            ) {
                if($token1->getType() === MySQLLexer::FULL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FULL_SYMBOL);
                }
                $children[] = $this->match(MySQLLexer::PROCESSLIST_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::FUNCTION_SYMBOL &&
                    $token2->getType() === MySQLLexer::STATUS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
                $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::FUNCTION_SYMBOL &&
                    $token2->getType() === MySQLLexer::CODE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FUNCTION_SYMBOL);
                $children[] = $this->match(MySQLLexer::CODE_SYMBOL);
                $children[] = $this->functionRef();
            } elseif ($token1->getType() === MySQLLexer::GRANTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::GRANTS_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL &&
                    $this->lexer->peekNextToken(2)->getType() !== MySQLLexer::USER_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                    $children[] = $this->user();
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                    $children[] = $this->user();
                    $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                    $children[] = $this->userList();
                }
            } elseif (($token1->getType() === MySQLLexer::GLOBAL_SYMBOL ||
                    $token1->getType() === MySQLLexer::LOCAL_SYMBOL ||
                    $token1->getType() === MySQLLexer::SESSION_SYMBOL ||
                    ($this->serverVersion >= 80000 &&
                        ($token1->getType() === MySQLLexer::PERSIST_SYMBOL ||
                        $token1->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL))) &&
                    ($token2->getType() === MySQLLexer::STATUS_SYMBOL ||
                    $token2->getType() === MySQLLexer::VARIABLES_SYMBOL)) {
                $children[] = $this->optionType();

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STATUS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::VARIABLES_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::VARIABLES_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $this->lexer->peekNextToken()->getText());
                }

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::MASTER_SYMBOL &&
                    $token2->getType() === MySQLLexer::STATUS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);
                $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                if ($this->serverVersion >= 50700 && $this->serverVersion < 50706 &&
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::NONBLOCKING_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::NONBLOCKING_SYMBOL);
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->channel();
                }
            } elseif ($token1->getType() === MySQLLexer::MASTER_SYMBOL && $token2->getType() === MySQLLexer::LOGS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::MASTER_SYMBOL);
                $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::OPEN_SYMBOL && $token2->getType() === MySQLLexer::TABLES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::OPEN_SYMBOL);
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::PLUGINS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PLUGINS_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::PROCEDURE_SYMBOL &&
                    $token2->getType() === MySQLLexer::CODE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
                $children[] = $this->match(MySQLLexer::CODE_SYMBOL);
                $children[] = $this->procedureRef();
            } elseif ($token1->getType() === MySQLLexer::PROCEDURE_SYMBOL &&
                    $token2->getType() === MySQLLexer::STATUS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PROCEDURE_SYMBOL);
                $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::PROFILE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PROFILE_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BLOCK_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::CONTEXT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::CPU_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IPC_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::MEMORY_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::PAGE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::SOURCE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::SWAPS_SYMBOL) {
                    $children[] = $this->profileType();

                    while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                        $children[] = $this->profileType();
                    }
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
                    $children[] = $this->match(MySQLLexer::QUERY_SYMBOL);
                    $children[] = $this->match(MySQLLexer::INT_NUMBER);
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
            } elseif ($token1->getType() === MySQLLexer::PROFILES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PROFILES_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::RELAYLOG_SYMBOL && $token2->getType() === MySQLLexer::EVENTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::RELAYLOG_SYMBOL);
                $children[] = $this->match(MySQLLexer::EVENTS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::IN_SYMBOL);
                    $children[] = $this->textString();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
                    $children[] = $this->ulonglong_number();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->channel();
                }
            } elseif ($token1->getType() === MySQLLexer::REPLICA_SYMBOL ||
                    $token1->getType() === MySQLLexer::SOURCE_SYMBOL) {
                if ($token1->getType() === MySQLLexer::REPLICA_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::REPLICA_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::SOURCE_SYMBOL);
                }

                $token = $this->lexer->peekNextToken();

                if ($token->getType() === MySQLLexer::STATUS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::HOSTS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::HOSTS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                    $children[] = $this->channel();
                }
            } elseif ($token1->getType() === MySQLLexer::SLAVE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SLAVE_SYMBOL);
                $token = $this->lexer->peekNextToken();
                if ($token->getType() === MySQLLexer::HOSTS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::HOSTS_SYMBOL);
                } elseif ($token->getType() === MySQLLexer::STATUS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                    if ($this->serverVersion >= 50700 && $this->serverVersion < 50706) {
                        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NONBLOCKING_SYMBOL) {
                            $children[] = $this->nonBlocking();
                        }
                    }
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                        $children[] = $this->channel();
                    }
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token->getText());
                }
            } elseif (
                $token1->getType() === MySQLLexer::ENGINES_SYMBOL || (
                    $token1->getType() === MySQLLexer::STORAGE_SYMBOL &&
                    $token2->getType() === MySQLLexer::ENGINES_SYMBOL 
                )
            ) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STORAGE_SYMBOL);
                }
                $children[] = $this->match(MySQLLexer::ENGINES_SYMBOL);
            } elseif (
                $token1->getType() === MySQLLexer::TABLE_SYMBOL &&
                $token2->getType() === MySQLLexer::STATUS_SYMBOL
            ) {
                $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
                $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif (
                (($token1->getType() === MySQLLexer::EXTENDED_SYMBOL ||
                    $token1->getText() === MySQLLexer::FULL_SYMBOL) &&
                    $token2->getType() === MySQLLexer::TABLES_SYMBOL)
                    || $token1->getType() === MySQLLexer::TABLES_SYMBOL
            ) {
                $children[] = $this->showCommandType();
                $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::TRIGGERS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::TRIGGERS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                    $children[] = $this->inDb();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::WARNINGS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WARNINGS_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIMIT_SYMBOL) {
                    $children[] = $this->limitClause();
                }
            } elseif ($token1->getType() === MySQLLexer::STATUS_SYMBOL ||
                    $token1->getType() === MySQLLexer::VARIABLES_SYMBOL) {
                if ($token1->getType() === MySQLLexer::STATUS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::VARIABLES_SYMBOL);
                }

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
                    $children[] = $this->likeOrWhere();
                }
            } elseif ($token1->getType() === MySQLLexer::PRIVILEGES_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PRIVILEGES_SYMBOL);
            } elseif ($token1->getType() === MySQLLexer::CONSTRAINTS_SYMBOL ||
                    $token1->getType() === MySQLLexer::INDEXES_SYMBOL ||
                    $token1->getType() === MySQLLexer::KEYS_SYMBOL) {
                if ($token1->getType() === MySQLLexer::CONSTRAINTS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::CONSTRAINTS_SYMBOL);
                } elseif ($token1->getType() === MySQLLexer::INDEXES_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::INDEXES_SYMBOL);
                } elseif ($token1->getType() === MySQLLexer::KEYS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEYS_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in showStatement: ' . $token1->getText());
                }
                $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
                $children[] = $this->tableRef();
            } else {
                throw new \Exception('Unexpected token in showStatement: ' . $token1->getText());
            }

            return new ASTNode('showStatement', $children);
        }

        public function showCommandType()
        {
            $token = $this->lexer->peekNextToken();
            $children = [];
            if ($token->getType() === MySQLLexer::EXTENDED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EXTENDED_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FULL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::FULL_SYMBOL);
                }
            } elseif ($token->getType() === MySQLLexer::FULL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FULL_SYMBOL);
                if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::EXTENDED_SYMBOL);
                }
            } else {
                throw new \Exception('Unexpected token in showCommandType: ' . $token->getText());
            }
            return new ASTNode('showCommandType', $children);
        }

        public function nonBlocking()
        {
            return $this->match(MySQLLexer::NONBLOCKING_SYMBOL);
        }

    public function isShowCommandType($token1, $token2)
    {
        if($token1->getType() === MySQLLexer::FULL_SYMBOL){
            return true;
        }
        if($this->serverVersion >= 80000 && $token1->getType() === MySQLLexer::EXTENDED_SYMBOL){
            return true;
        }
    }

    public function fromOrIn()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::FROM_SYMBOL:
        case MySQLLexer::IN_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function inDb()
    {
        $children = [];

        $children[] = $this->fromOrIn();
        $children[] = $this->identifier();

        return new ASTNode('inDb', $children);
    }

    public function profileType()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::BLOCK_SYMBOL) {
            $this->match(MySQLLexer::BLOCK_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BLOCK_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::IO_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CONTEXT_SYMBOL) {
            $this->match(MySQLLexer::CONTEXT_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CONTEXT_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::SWITCHES_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PAGE_SYMBOL) {
            $this->match(MySQLLexer::PAGE_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PAGE_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::FAULTS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ALL_SYMBOL ||
                  $token->getType() === MySQLLexer::CPU_SYMBOL ||
                  $token->getType() === MySQLLexer::IPC_SYMBOL ||
                  $token->getType() === MySQLLexer::MEMORY_SYMBOL ||
                  $token->getType() === MySQLLexer::SOURCE_SYMBOL ||
                  $token->getType() === MySQLLexer::SWAPS_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children = [new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()))];
        } else {
            throw new \Exception('Unexpected token in profileType: ' . $token->getText());
        }

        return new ASTNode('profileType', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function otherAdministrativeStatement()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::BINLOG_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BINLOG_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::CACHE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CACHE_SYMBOL);
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            $children[] = $this->keyCacheListOrParts();
            $children[] = $this->match(MySQLLexer::IN_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->identifier();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in otherAdministrativeStatement: ' . $this->lexer->peekNextToken()->getText());
            }
        } elseif ($token->getType() === MySQLLexer::FLUSH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FLUSH_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
                $children[] = $this->noWriteToBinLog();
            }

            $token = $this->lexer->peekNextToken();
            if (($token->getType() === MySQLLexer::TABLES_SYMBOL ||
                 $token->getType() === MySQLLexer::TABLE_SYMBOL)) {
                $children[] = $this->flushTables();
            } elseif ($this->isFlushOptionStart($token)) {
                $children[] = $this->flushOption();

                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                    $children[] = $this->flushOption();
                }
            } else {
                throw new \Exception('Unexpected token in otherAdministrativeStatement: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::KILL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KILL_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CONNECTION_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::QUERY_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CONNECTION_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::CONNECTION_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::QUERY_SYMBOL);
                }
            }

            $children[] = $this->expr();
        } elseif ($token->getType() === MySQLLexer::LOAD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOAD_SYMBOL);
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
            $children[] = $this->match(MySQLLexer::CACHE_SYMBOL);
            $children[] = $this->preloadTail();
        } elseif ($token->getType() === MySQLLexer::SHUTDOWN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SHUTDOWN_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in otherAdministrativeStatement: ' . $token->getText());
        }

        return new ASTNode('otherAdministrativeStatement', $children);
    }

    private function isFlushOptionStart($token)
    {
        return $token->getType() === MySQLLexer::DES_KEY_FILE_SYMBOL ||
               $token->getType() === MySQLLexer::HOSTS_SYMBOL ||
               $token->getType() === MySQLLexer::LOGS_SYMBOL ||
               ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::QUERY_SYMBOL) ||
               $token->getType() === MySQLLexer::PRIVILEGES_SYMBOL ||
               ($this->serverVersion >= 50706 && $token->getType() === MySQLLexer::OPTIMIZER_COSTS_SYMBOL) ||
               $token->getType() === MySQLLexer::RELAY_SYMBOL ||
               ($token->getType() === MySQLLexer::BINARY_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) ||
               ($token->getType() === MySQLLexer::ENGINE_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) ||
               ($token->getType() === MySQLLexer::ERROR_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) ||
               ($token->getType() === MySQLLexer::GENERAL_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) ||
               ($token->getType() === MySQLLexer::SLOW_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) ||
               $token->getType() === MySQLLexer::STATUS_SYMBOL ||
               $token->getType() === MySQLLexer::USER_RESOURCES_SYMBOL;
    }

    public function keyCacheListOrParts()
    {
        if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::PARTITION_SYMBOL) {
            return $this->assignToKeycachePartition();
        } else {
            return $this->keyCacheList();
        }
    }

    public function keyCacheList()
    {
        $children = [];

        $children[] = $this->assignToKeycache();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->assignToKeycache();
        }

        return new ASTNode('keyCacheList', $children);
    }

    public function assignToKeycache()
    {
        $children = [];

        $children[] = $this->tableRef();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->cacheKeyList();
        }

        return new ASTNode('assignToKeycache', $children);
    }

    public function assignToKeycachePartition()
    {
        $children = [];

        $children[] = $this->tableRef();
        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->allOrPartitionNameList();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->cacheKeyList();
        }

        return new ASTNode('assignToKeycachePartition', $children);
    }

    public function cacheKeyList()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in cacheKeyList: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL) {
            $children[] = $this->keyUsageList();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('cacheKeyList', $children);
    }

    public function keyUsageList()
    {
        $children = [];

        $children[] = $this->keyUsageElement();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->keyUsageElement();
        }

        return new ASTNode('keyUsageList', $children);
    }

    public function keyUsageElement()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            return $this->identifier();
        } elseif ($token->getType() === MySQLLexer::PRIMARY_SYMBOL) {
            return $this->match(MySQLLexer::PRIMARY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in keyUsageElement: ' . $token->getText());
        }
    }

    public function flushOption()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DES_KEY_FILE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DES_KEY_FILE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::HOSTS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::HOSTS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::LOGS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::QUERY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::QUERY_SYMBOL);
            $children[] = $this->match(MySQLLexer::CACHE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PRIVILEGES_SYMBOL) {
            $this->match(MySQLLexer::PRIVILEGES_SYMBOL);
            $children[] = ASTNode::fromToken($token);
        } elseif ($this->serverVersion >= 50706 && $token->getType() === MySQLLexer::OPTIMIZER_COSTS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPTIMIZER_COSTS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::RELAY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RELAY_SYMBOL);
            $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
                $children[] = $this->channel();
            }
        } elseif (($token->getType() === MySQLLexer::BINARY_SYMBOL ||
                   $token->getType() === MySQLLexer::ENGINE_SYMBOL ||
                   $token->getType() === MySQLLexer::ERROR_SYMBOL ||
                   $token->getType() === MySQLLexer::GENERAL_SYMBOL ||
                   $token->getType() === MySQLLexer::SLOW_SYMBOL) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOGS_SYMBOL) {
            if ($token->getType() === MySQLLexer::BINARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::ERROR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ERROR_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::GENERAL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::GENERAL_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::SLOW_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SLOW_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in flushOption: ' . $token->getText());
            }
            $children[] = $this->match(MySQLLexer::LOGS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::STATUS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STATUS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::USER_RESOURCES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USER_RESOURCES_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in flushOption: ' . $token->getText());
        }

        return new ASTNode('flushOption', $children);
    }

    public function flushTables()
    {
        $children = [];

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::TABLES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLES_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in flushTables: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->match(MySQLLexer::READ_SYMBOL);
                $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);
            } else {
                $children[] = $this->identifierList();
                if ($this->serverVersion >= 50606 &&
                    ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL)) {
                    $children[] = $this->flushTablesOptions();
                }
            }
        }

        return new ASTNode('flushTables', $children);
    }

    public function preloadTail()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->tableRef();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITION_SYMBOL) {
                $children[] = $this->adminPartition();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                $children[] = $this->cacheKeyList();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
                $children[] = $this->match(MySQLLexer::LEAVES_SYMBOL);
            }
        } else {
            $children[] = $this->preloadList();
        }
        return new ASTNode('preloadTail', $children);
    }

    public function preloadList()
    {
        $children = [];

        $children[] = $this->preloadKeys();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->preloadKeys();
        }

        return new ASTNode('preloadList', $children);
    }

    public function preloadKeys()
    {
        $children = [];
        $children[] = $this->tableRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
            $children[] = $this->cacheKeyList();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IGNORE_SYMBOL);
            $children[] = $this->match(MySQLLexer::LEAVES_SYMBOL);
        }
        return new ASTNode('preloadKeys', $children);
    }

    public function adminPartition()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->allOrPartitionNameList();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('adminPartition', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function resourceGroupManagement()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::CREATE_SYMBOL) {
            return $this->createResourceGroup();
        } elseif ($token->getType() === MySQLLexer::ALTER_SYMBOL) {
            return $this->alterResourceGroup();
        } elseif ($token->getType() === MySQLLexer::SET_SYMBOL) {
            return $this->setResourceGroup();
        } elseif ($token->getType() === MySQLLexer::DROP_SYMBOL) {
            return $this->dropResourceGroup();
        } else {
            throw new \Exception('Unexpected token in resourceGroupManagement: ' . $token->getText());
        }
    }

    public function createResourceGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CREATE_SYMBOL);
        $children[] = $this->match(MySQLLexer::RESOURCE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->identifier();
        $children[] = $this->match(MySQLLexer::TYPE_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
            $children[] = $this->equal();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USER_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::SYSTEM_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SYSTEM_SYMBOL);
        } else {
            throw new \Exception(
                'Unexpected token in createResourceGroup: ' . $this->lexer->peekNextToken()->getText()
            );
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VCPU_SYMBOL) {
            $children[] = $this->resourceGroupVcpuList();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::THREAD_PRIORITY_SYMBOL) {
            $children[] = $this->resourceGroupPriority();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DISABLE_SYMBOL) {
            $children[] = $this->resourceGroupEnableDisable();
        }

        return new ASTNode('createResourceGroup', $children);
    }

    public function resourceGroupVcpuList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::VCPU_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
            $children[] = $this->equal();
        }

        $children[] = $this->vcpuNumOrRange();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->vcpuNumOrRange();
        }
        return new ASTNode('resourceGroupVcpuList', $children);
    }

    public function vcpuNumOrRange()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::INT_NUMBER);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::MINUS_OPERATOR) {
            $children[] = $this->match(MySQLLexer::MINUS_OPERATOR);
            $children[] = $this->match(MySQLLexer::INT_NUMBER);
        }
        return new ASTNode('vcpuNumOrRange', $children);
    }

    public function resourceGroupPriority()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::THREAD_PRIORITY_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
            $children[] = $this->equal();
        }
        $children[] = $this->match(MySQLLexer::INT_NUMBER);
        return new ASTNode('resourceGroupPriority', $children);
    }

    public function resourceGroupEnableDisable()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL) {
            return $this->match(MySQLLexer::ENABLE_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::DISABLE_SYMBOL) {
            return $this->match(MySQLLexer::DISABLE_SYMBOL);
        } else {
            throw new \Exception(
                'Unexpected token in resourceGroupEnableDisable: ' . $this->lexer->peekNextToken()->getText()
            );
        }
    }

    public function alterResourceGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ALTER_SYMBOL);
        $children[] = $this->match(MySQLLexer::RESOURCE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->resourceGroupRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VCPU_SYMBOL) {
            $children[] = $this->resourceGroupVcpuList();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::THREAD_PRIORITY_SYMBOL) {
            $children[] = $this->resourceGroupPriority();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENABLE_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DISABLE_SYMBOL) {
            $children[] = $this->resourceGroupEnableDisable();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FORCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FORCE_SYMBOL);
        }
        return new ASTNode('alterResourceGroup', $children);
    }

    public function setResourceGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SET_SYMBOL);
        $children[] = $this->match(MySQLLexer::RESOURCE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->identifier();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->threadIdList();
        }

        return new ASTNode('setResourceGroup', $children);
    }

    public function threadIdList()
    {
        $children = [];
        $children[] = $this->real_ulong_number();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->real_ulong_number();
        }
        return new ASTNode('threadIdList', $children);
    }

    public function dropResourceGroup()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DROP_SYMBOL);
        $children[] = $this->match(MySQLLexer::RESOURCE_SYMBOL);
        $children[] = $this->match(MySQLLexer::GROUP_SYMBOL);
        $children[] = $this->resourceGroupRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::FORCE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FORCE_SYMBOL);
        }
        return new ASTNode('dropResourceGroup', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function utilityStatement()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::EXPLAIN_SYMBOL ||
            $token->getType() === MySQLLexer::DESCRIBE_SYMBOL ||
            $token->getType() === MySQLLexer::DESC_SYMBOL) {
            return $this->explainStatement();
        } elseif ($token->getType() === MySQLLexer::HELP_SYMBOL) {
            return $this->helpCommand();
        } elseif ($token->getType() === MySQLLexer::USE_SYMBOL) {
            return $this->useCommand();
        } elseif ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::RESTART_SYMBOL) {
            return $this->restartServer();
        } else {
            throw new \Exception('Unexpected token in utilityStatement: ' . $token->getText());
        }
    }

    public function describeStatement()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::DESCRIBE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DESCRIBE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DESC_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DESC_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::EXPLAIN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EXPLAIN_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in describeStatement: ' . $token->getText());
        }

        $children[] = $this->tableRef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $children[] = $this->textString();
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->columnRef();
        }
        return new ASTNode('describeStatement', $children);
    }

    public function explainStatement()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DESCRIBE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DESCRIBE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DESC_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DESC_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::EXPLAIN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EXPLAIN_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in explainStatement: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::EXTENDED_SYMBOL ||
               ($this->serverVersion < 80000 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITIONS_SYMBOL) ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::FORMAT_SYMBOL ||
               ($this->serverVersion >= 80018 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ANALYZE_SYMBOL)) {
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::EXTENDED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EXTENDED_SYMBOL);
            } elseif ($this->serverVersion < 80000              && $token->getType() === MySQLLexer::PARTITIONS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PARTITIONS_SYMBOL);
            } elseif ($this->serverVersion >= 50605 && $token->getType() === MySQLLexer::FORMAT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FORMAT_SYMBOL);
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
                $children[] = $this->textOrIdentifier();
            } elseif ($this->serverVersion >= 80018 && $token->getType() === MySQLLexer::ANALYZE_SYMBOL &&
                      $this->lexer->peekNextToken(2)->getType() !== MySQLLexer::FORMAT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ANALYZE_SYMBOL);
            } elseif ($this->serverVersion >= 80019 && $token->getType() === MySQLLexer::ANALYZE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ANALYZE_SYMBOL);
                $children[] = $this->match(MySQLLexer::FORMAT_SYMBOL);
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
                $children[] = $this->textOrIdentifier();
            } else {
                throw new \Exception('Unexpected token in explainStatement: ' . $token->getText());
            }
        }

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
            $token->getType() === MySQLLexer::WITH_SYMBOL ||
            $token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
            ($this->serverVersion >= 50603 &&
             ($token->getType() === MySQLLexer::DELETE_SYMBOL ||
              $token->getType() === MySQLLexer::INSERT_SYMBOL ||
              $token->getType() === MySQLLexer::REPLACE_SYMBOL ||
              $token->getType() === MySQLLexer::UPDATE_SYMBOL))) {
            $children[] = $this->explainableStatement();
        } elseif ($this->serverVersion >= 50700 && $token->getText() === 'FOR CONNECTION') {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::CONNECTION_SYMBOL);
            $children[] = $this->real_ulong_number();
        } else {
            throw new \Exception('Unexpected token in explainStatement: ' . $token->getText());
        }

        return new ASTNode('explainStatement', $children);
    }

    // Before server version 5.6 only select statements were explainable.
    public function explainableStatement()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SELECT_SYMBOL ||
            $token->getType() === MySQLLexer::WITH_SYMBOL ||
            $token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            return $this->selectStatement();
        } elseif ($this->serverVersion >= 50603 &&
                  ($token->getType() === MySQLLexer::DELETE_SYMBOL ||
                   $token->getType() === MySQLLexer::INSERT_SYMBOL ||
                   $token->getType() === MySQLLexer::REPLACE_SYMBOL ||
                   $token->getType() === MySQLLexer::UPDATE_SYMBOL)) {
            if ($token->getType() === MySQLLexer::DELETE_SYMBOL) {
                return $this->deleteStatement();
            } elseif ($token->getType() === MySQLLexer::INSERT_SYMBOL) {
                return $this->insertStatement();
            } elseif ($token->getType() === MySQLLexer::REPLACE_SYMBOL) {
                return $this->replaceStatement();
            } elseif ($token->getType() === MySQLLexer::UPDATE_SYMBOL) {
                return $this->updateStatement();
            } else {
                throw new \Exception('Unexpected token in explainableStatement: ' . $token->getText());
            }
        } elseif ($this->serverVersion >= 50700 && $token->getText() === 'FOR CONNECTION') {
            $children = [];
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::CONNECTION_SYMBOL);
            $children[] = $this->real_ulong_number();
            return new ASTNode('explainableStatement', $children);
        } else {
            throw new \Exception('Unexpected token in explainableStatement: ' . $token->getText());
        }
    }

    public function helpCommand()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::HELP_SYMBOL);
        $children[] = $this->textOrIdentifier();

        return new ASTNode('helpCommand', $children);
    }

    public function useCommand()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::USE_SYMBOL);
        $children[] = $this->identifier();

        return new ASTNode('useCommand', $children);
    }

    public function restartServer()
    {
        return $this->match(MySQLLexer::RESTART_SYMBOL);
    }

    //----------------- Expression support ---------------------------------------------------------------------------------

    public function expr()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if($this->isBoolPriStart($token)) {
            $children[] = $this->boolPri();
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::IS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::IS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::NOT2_SYMBOL) {
                    $children[] = $this->notRule();
                }
                $token = $this->lexer->getNextToken();
                switch ($token->getType()) {
                    case MySQLLexer::TRUE_SYMBOL:
                    case MySQLLexer::FALSE_SYMBOL:
                    case MySQLLexer::UNKNOWN_SYMBOL:
                        $children[] = ASTNode::fromToken($this->lexer->getNextToken());
                        break;
                    default:
                        throw new \Exception('Unexpected token in expr: ' . $token->getText());
                }
            }

            if ($token->getType() === MySQLLexer::AND_SYMBOL ||
                $token->getType() === MySQLLexer::LOGICAL_AND_OPERATOR ||
                $token->getType() === MySQLLexer::XOR_SYMBOL ||
                $token->getType() === MySQLLexer::OR_SYMBOL ||
                $token->getType() === MySQLLexer::LOGICAL_OR_OPERATOR
            ) {
                $children[] = ASTNode::fromToken($this->lexer->getNextToken());
                $children[] = $this->expr();
            }
        } elseif ($token->getType() === MySQLLexer::NOT_SYMBOL) {
            $children[] = $this->notRule();
            $children[] = $this->expr();
        } else {
            throw new \Exception('Unexpected token in expr: ' . $token->getText());
        }

        return new ASTNode('expr', $children);
    }

    private function isCompoundStatementStart($token)
    {
        return $this->isSimpleStatementStart($token) ||
               $token->getType() === MySQLLexer::RETURN_SYMBOL ||
               $token->getType() === MySQLLexer::DECLARE_SYMBOL ||
               $token->getType() === MySQLLexer::IF_SYMBOL ||
               $token->getType() === MySQLLexer::CASE_SYMBOL ||
               $token->getType() === MySQLLexer::BEGIN_SYMBOL ||
               $token->getType() === MySQLLexer::LOOP_SYMBOL ||
               $token->getType() === MySQLLexer::REPEAT_SYMBOL ||
               $token->getType() === MySQLLexer::WHILE_SYMBOL ||
               $token->getType() === MySQLLexer::LEAVE_SYMBOL ||
               $token->getType() === MySQLLexer::ITERATE_SYMBOL;
    }

    public function elseStatement()
    {
        $this->match(MySQLLexer::ELSE_SYMBOL);
        $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ELSE_SYMBOL)), $this->compoundStatementList()];
        return new ASTNode('elseStatement', $children);
    }

    public function indexTypeClause()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
            $this->match(MySQLLexer::USING_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::USING_SYMBOL));
        } else {
            $this->match(MySQLLexer::TYPE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::TYPE_SYMBOL));
        }

        $children[] = $this->indexType();

        return new ASTNode('indexTypeClause', $children);
    }

    public function visibility()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::VISIBLE_SYMBOL) {
            return $this->match(MySQLLexer::VISIBLE_SYMBOL);
        } else {
            return $this->match(MySQLLexer::INVISIBLE_SYMBOL);
        }
    }

    public function systemVariable()
    {
        $children = [];

        $this->match(MySQLLexer::AT_AT_SIGN_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::AT_AT_SIGN_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::GLOBAL_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::LOCAL_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::SESSION_SYMBOL) {
            $children[] = $this->setVarIdentType();
        }
        $children[] = $this->textOrIdentifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->dotIdentifier();
        }
        return new ASTNode('systemVariable', $children);
    }

    public function windowFunctionCall()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        
        if ($token->getType() === MySQLLexer::ROW_NUMBER_SYMBOL ||
            $token->getType() === MySQLLexer::RANK_SYMBOL ||
            $token->getType() === MySQLLexer::DENSE_RANK_SYMBOL ||
            $token->getType() === MySQLLexer::CUME_DIST_SYMBOL ||
            $token->getType() === MySQLLexer::PERCENT_RANK_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $children[] = $this->parentheses();
        } elseif ($token->getType() === MySQLLexer::NTILE_SYMBOL) {
            $this->match(MySQLLexer::NTILE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NTILE_SYMBOL));
            $children[] = $this->simpleExprWithParentheses();
        } elseif ($token->getType() === MySQLLexer::LEAD_SYMBOL ||
                  $token->getType() === MySQLLexer::LAG_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->leadLagInfo();
            }
            $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESPECT_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::IGNORE_SYMBOL) {
                $children[] = $this->nullTreatment();
            }
        } else {
            throw new \Exception('Unexpected token in windowFunctionCall: ' . $token->getText());
        }
        $children[] = $this->windowingClause();
        return new ASTNode('windowFunctionCall', $children);
    }

    public function sumExpr()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::AVG_SYMBOL ||
            $token->getType() === MySQLLexer::BIT_AND_SYMBOL ||
            $token->getType() === MySQLLexer::BIT_OR_SYMBOL ||
            $token->getType() === MySQLLexer::BIT_XOR_SYMBOL ||
            $token->getType() === MySQLLexer::COUNT_SYMBOL ||
            $token->getType() === MySQLLexer::MAX_SYMBOL ||
            $token->getType() === MySQLLexer::MIN_SYMBOL ||
            $token->getType() === MySQLLexer::STD_SYMBOL ||
            $token->getType() === MySQLLexer::SUM_SYMBOL ||
            $token->getType() === MySQLLexer::VARIANCE_SYMBOL ||
            $token->getType() === MySQLLexer::STDDEV_POP_SYMBOL ||
            $token->getType() === MySQLLexer::VAR_POP_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DISTINCT_SYMBOL) {
                $this->match(MySQLLexer::DISTINCT_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DISTINCT_SYMBOL));
            }
            $children[] = $this->expr();
            $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        } elseif ($token->getType() === MySQLLexer::STDDEV_SAMP_SYMBOL ||
                  $token->getType() === MySQLLexer::VAR_SAMP_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
            $children[] = $this->expr();
            $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        } elseif ($token->getType() === MySQLLexer::GROUP_CONCAT_SYMBOL) {
            $this->match(MySQLLexer::GROUP_CONCAT_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::GROUP_CONCAT_SYMBOL));
            $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DISTINCT_SYMBOL) {
                $this->match(MySQLLexer::DISTINCT_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DISTINCT_SYMBOL));
            }
            $children[] = $this->exprList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ORDER_SYMBOL) {
                $children[] = $this->orderClause();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SEPARATOR_SYMBOL) {
                $this->match(MySQLLexer::SEPARATOR_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::SEPARATOR_SYMBOL));
                $children[] = $this->textLiteral();
            }
            $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        } else {
            throw new \Exception('Unexpected token in sumExpr: ' . $token->getText());
        }
        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::OVER_SYMBOL) {
            $children[] = $this->windowingClause();
        }
        return new ASTNode('columnRefOrLiteral', $children);
    }

    public function leadLagInfo()
    {
        $children = [];

        $this->match(MySQLLexer::COMMA_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::COMMA_SYMBOL));
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ULONGLONG_NUMBER) {
            $this->match(MySQLLexer::ULONGLONG_NUMBER);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ULONGLONG_NUMBER));
        } elseif ($token->getType() === MySQLLexer::PARAM_MARKER) {
            $this->match(MySQLLexer::PARAM_MARKER);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PARAM_MARKER));
        } else {
            throw new \Exception('Unexpected token in leadLagInfo: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::COMMA_SYMBOL));
            $children[] = $this->expr();
        }

        return new ASTNode('leadLagInfo', $children);
    }

    public function nullTreatment()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RESPECT_SYMBOL) {
            $this->match(MySQLLexer::RESPECT_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::RESPECT_SYMBOL));
        } else {
            $this->match(MySQLLexer::IGNORE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::IGNORE_SYMBOL));
        }
        $this->match(MySQLLexer::NULLS_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NULLS_SYMBOL));
        return new ASTNode('nullTreatment', $children);
    }

    public function windowingClause()
    {
        $children = [];
        $this->match(MySQLLexer::OVER_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OVER_SYMBOL));
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->windowSpec();
        } else {
            $children[] = $this->windowName();
        }
        return new ASTNode('windowingClause', $children);
     }

    public function boolPri()
    {
        $children = [];
        $children[] = $this->predicate();
        $token = $this->lexer->peekNextToken();
        while ($token->getType() === MySQLLexer::IS_SYMBOL ||
               $this->isCompOp($token) ||
               ($this->serverVersion >= 80017 && $token->getText() === 'MEMBER OF') ||
               $token->getText() === 'SOUNDS LIKE') {
            if ($token->getType() === MySQLLexer::IS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::IS_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::NOT2_SYMBOL) {
                    $children[] = $this->notRule();
                }
                $children[] = $this->match(MySQLLexer::NULL_SYMBOL);
            } elseif ($this->isCompOp($token)) {
                $this->compOp();
                $children[] = new ASTNode(MySQLLexer::getTokenName($token->getType()));
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::ANY_SYMBOL) {
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ALL_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::ALL_SYMBOL);
                    } else {
                        $children[] = $this->match(MySQLLexer::ANY_SYMBOL);
                    }
                    $children[] = $this->subquery();
                } else {
                    $children[] = $this->predicate();
                }
            } elseif ($this->serverVersion >= 80017 && $token->getText() === 'MEMBER OF') {
                $children[] = $this->match(MySQLLexer::MEMBER_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OF_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::OF_SYMBOL);
                }
                $children[] = $this->simpleExprWithParentheses();
            } elseif ($token->getText() === 'SOUNDS LIKE') {
                $children[] = $this->match(MySQLLexer::SOUNDS_SYMBOL);
                $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
                $children[] = $this->bitExpr();
            } else {
                throw new \Exception('Unexpected token in boolPri: ' . $token->getText());
            }
            $token = $this->lexer->peekNextToken();
        }
        return new ASTNode('boolPri', $children);
    }

    public function compOp()
    {
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::EQUAL_OPERATOR:
            case MySQLLexer::NULL_SAFE_EQUAL_OPERATOR:
            case MySQLLexer::GREATER_OR_EQUAL_OPERATOR:
            case MySQLLexer::GREATER_THAN_OPERATOR:
            case MySQLLexer::LESS_OR_EQUAL_OPERATOR:
            case MySQLLexer::LESS_THAN_OPERATOR:
            case MySQLLexer::NOT_EQUAL_OPERATOR:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in compOp: ' . $token->getText());
        }
    }

    public function predicate()
    {
        $children = [];
        $children[] = $this->bitExpr();
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::NOT_SYMBOL ||
            $token->getType() === MySQLLexer::NOT2_SYMBOL ||
            $token->getType() === MySQLLexer::IN_SYMBOL ||
            $token->getType() === MySQLLexer::BETWEEN_SYMBOL ||
            $token->getType() === MySQLLexer::LIKE_SYMBOL ||
            $token->getType() === MySQLLexer::REGEXP_SYMBOL ||
            ($this->serverVersion >= 80017 && $token->getText() === 'MEMBER OF') ||
            $token->getText() === 'SOUNDS LIKE') {
            if ($token->getType() === MySQLLexer::NOT_SYMBOL ||
                $token->getType() === MySQLLexer::NOT2_SYMBOL ||
                $token->getType() === MySQLLexer::IN_SYMBOL ||
                $token->getType() === MySQLLexer::BETWEEN_SYMBOL ||
                $token->getType() === MySQLLexer::LIKE_SYMBOL ||
                $token->getType() === MySQLLexer::REGEXP_SYMBOL) {
                if ($token->getType() === MySQLLexer::NOT_SYMBOL || $token->getType() === MySQLLexer::NOT2_SYMBOL) {
                    $children[] = $this->notRule();
                }
                $children[] = $this->predicateOperations();
            } elseif ($this->serverVersion >= 80017 && $token->getText() === 'MEMBER OF') {
                $children[] = $this->match(MySQLLexer::MEMBER_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OF_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::OF_SYMBOL);
                }
                $children[] = $this->simpleExprWithParentheses();
            } elseif ($token->getText() === 'SOUNDS LIKE') {
                $children[] = $this->match(MySQLLexer::SOUNDS_SYMBOL);
                $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
                $children[] = $this->bitExpr();
            } else {
                throw new \Exception('Unexpected token in predicate: ' . $token->getText());
            }
        }

        return new ASTNode('predicate', $children);
    }

    public function predicateOperations()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::IN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IN_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL &&
                ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::SELECT_SYMBOL ||
                 $this->lexer->peekNextToken(2)->getType() === MySQLLexer::WITH_SYMBOL ||
                 $this->lexer->peekNextToken(2)->getType() === MySQLLexer::OPEN_PAR_SYMBOL)) {
                $children[] = $this->subquery();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = $this->exprList();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in predicateOperations: ' . $this->lexer->peekNextToken()->getText());
            }
            return new ASTNode('predicateOperations', $children);
        } elseif ($token->getType() === MySQLLexer::BETWEEN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BETWEEN_SYMBOL);
            $children[] = $this->bitExpr();
            $children[] = $this->match(MySQLLexer::AND_SYMBOL);
            $children[] = $this->predicate();
            return new ASTNode('predicateOperations', $children);
        } elseif ($token->getType() === MySQLLexer::LIKE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
            $children[] = $this->simpleExpr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ESCAPE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ESCAPE_SYMBOL);
                $children[] = $this->simpleExpr();
            }
            return new ASTNode('predicateOperations', $children);
        } elseif ($token->getType() === MySQLLexer::REGEXP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::REGEXP_SYMBOL);
            $children[] = $this->bitExpr();
            return new ASTNode('predicateOperations', $children);
        } else {
            throw new \Exception('Unexpected token in predicateOperations: ' . $token->getText());
        }
    }

    public function bitExpr()
    {
        $children = [];
        $children[] = $this->simpleExpr();
        $token = $this->lexer->peekNextToken();

        while (
            $token->getType() === MySQLLexer::BITWISE_XOR_OPERATOR ||
            $token->getType() === MySQLLexer::MULT_OPERATOR ||
            $token->getType() === MySQLLexer::DIV_OPERATOR ||
            $token->getType() === MySQLLexer::MOD_OPERATOR ||
            $token->getType() === MySQLLexer::DIV_SYMBOL ||
            $token->getType() === MySQLLexer::MOD_SYMBOL ||
            $token->getType() === MySQLLexer::PLUS_OPERATOR ||
            $token->getType() === MySQLLexer::MINUS_OPERATOR ||
            $token->getType() === MySQLLexer::SHIFT_LEFT_OPERATOR ||
            $token->getType() === MySQLLexer::SHIFT_RIGHT_OPERATOR ||
            $token->getType() === MySQLLexer::BITWISE_AND_OPERATOR ||
            $token->getType() === MySQLLexer::BITWISE_OR_OPERATOR
        ) {
            if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::INTERVAL_SYMBOL) {
                break;
            }
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::BITWISE_XOR_OPERATOR) {
                $children[] = $this->match(MySQLLexer::BITWISE_XOR_OPERATOR);
                $children[] = $this->bitExpr();
            } elseif ($token->getType() === MySQLLexer::MULT_OPERATOR ||
                        $token->getType() === MySQLLexer::DIV_OPERATOR ||
                        $token->getType() === MySQLLexer::MOD_OPERATOR ||
                        $token->getType() === MySQLLexer::DIV_SYMBOL ||
                        $token->getType() === MySQLLexer::MOD_SYMBOL) {
                $this->match($token->getType());
                $children[] = new ASTNode(MySQLLexer::getTokenName($token->getType()));
                $children[] = $this->bitExpr();
            } elseif ($token->getType() === MySQLLexer::PLUS_OPERATOR ||
                        $token->getType() === MySQLLexer::MINUS_OPERATOR) {
                $this->match($token->getType());
                $children[] = new ASTNode(MySQLLexer::getTokenName($token->getType()));
                $children[] = $this->bitExpr();
            } elseif ($token->getType() === MySQLLexer::SHIFT_LEFT_OPERATOR ||
                        $token->getType() === MySQLLexer::SHIFT_RIGHT_OPERATOR) {
                $this->match($token->getType());
                $children[] = new ASTNode(MySQLLexer::getTokenName($token->getType()));
                $children[] = $this->bitExpr();
            } elseif ($token->getType() === MySQLLexer::BITWISE_AND_OPERATOR) {
                $children[] = $this->match(MySQLLexer::BITWISE_AND_OPERATOR);
                $children[] = $this->bitExpr();
            } elseif ($token->getType() === MySQLLexer::BITWISE_OR_OPERATOR) {
                $children[] = $this->match(MySQLLexer::BITWISE_OR_OPERATOR);
                $children[] = $this->bitExpr();
            } else {
                throw new \Exception('Unexpected token in bitExpr: ' . $token->getText());
            }

            $token = $this->lexer->peekNextToken();
        }

        if ($token->getType() === MySQLLexer::PLUS_OPERATOR ||
            $token->getType() === MySQLLexer::MINUS_OPERATOR) {
            $this->match($token->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($token->getType()));
            $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->interval();
        }

        return new ASTNode('bitExpr', $children);
    }

    public function simpleExpr()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->variable();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->equal();
                $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
                $children[] = $this->expr();
            }
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isUnambiguousIdentifierStart($token)) {
            if ($this->lexer->peekNextToken(2)->getType() !== MySQLLexer::OPEN_PAR_SYMBOL) {
                return $this->columnRefOrLiteral();
            }
            return $this->functionCall();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token) ||
                  $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->columnRef();
            if ($this->serverVersion >= 50708 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::JSON_SEPARATOR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::JSON_UNQUOTED_SEPARATOR_SYMBOL)) {
                $children[] = $this->jsonOperator();
            }
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isRuntimeFunctionCallStart($token)) {
            $children[] = $this->runtimeFunctionCall();
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isLiteralStart($token)) {
            $children[] = $this->literal();
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::PARAM_MARKER) {
            $children[] = $this->match(MySQLLexer::PARAM_MARKER);
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isSumExprStart($token)) {
            $children[] = $this->sumExpr();
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::GROUPING_SYMBOL) {
            $children[] = $this->match(MySQLLexer::GROUPING_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->exprList();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->serverVersion >= 80000 && $this->isWindowFunctionCallStart($token)) {
            $children[] = $this->windowFunctionCall();
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::PLUS_OPERATOR ||
                  $token->getType() === MySQLLexer::MINUS_OPERATOR ||
                  $token->getType() === MySQLLexer::BITWISE_NOT_OPERATOR) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $children[] = $this->simpleExpr();
            return new ASTNode('simpleExpr', $children);
        } elseif (
            $token->getType() === MySQLLexer::LOGICAL_NOT_OPERATOR || 
            $token->getType() === MySQLLexer::NOT2_SYMBOL
        ) {
            $children[] = $this->not2Rule();
            $children[] = $this->simpleExpr();
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::ROW_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROW_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->exprList();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::EXISTS_SYMBOL ||
                  $this->isSubqueryStart($this->lexer->peekNextToken(2))) {
            if ($token->getType() === MySQLLexer::EXISTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::EXISTS_SYMBOL);
            }
            $children[] = $this->subquery();
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->exprList();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::OPEN_CURLY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_CURLY_SYMBOL);
            $children[] = $this->identifier();
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_CURLY_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::MATCH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MATCH_SYMBOL);
            $children[] = $this->identListArg();
            $children[] = $this->match(MySQLLexer::AGAINST_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->bitExpr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
                $children[] = $this->fulltextOptions();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::BINARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            $children[] = $this->simpleExpr();
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::CAST_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CAST_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::AS_SYMBOL);
            $children[] = $this->castType();
            if ($this->serverVersion >= 80017 && $this->lexer->peekNextToken()->getType() === MySQLLexer::ARRAY_SYMBOL) {
                $children[] = $this->arrayCast();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::CASE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CASE_SYMBOL);
            if ($this->isBoolPriStart($this->lexer->peekNextToken())) {
                $children[] = $this->expr();
            }
            do {
                $children[] = $this->whenExpression();
                $children[] = $this->thenExpression();
            } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHEN_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ELSE_SYMBOL) {
                $children[] = $this->elseExpression();
            }
            $children[] = $this->match(MySQLLexer::END_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::CONVERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONVERT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->castType();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
                $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                $children[] = $this->charsetName();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in simpleExpr: ' . $this->lexer->peekNextToken()->getText());
            }
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getText() === 'DEFAULT') {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->simpleIdentifier();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getText() === 'VALUES') {
            $children[] = $this->match(MySQLLexer::VALUES_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->simpleIdentifier();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('simpleExpr', $children);
        } elseif ($token->getType() === MySQLLexer::INTERVAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->interval();
            $children[] = $this->match(MySQLLexer::PLUS_OPERATOR);
            $children[] = $this->expr();
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isSimpleExprStart($token) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::COLLATE_SYMBOL) {
            $children[] = $this->simpleExpr();
            $children[] = $this->match(MySQLLexer::COLLATE_SYMBOL);
            $children[] = $this->textOrIdentifier();
            return new ASTNode('simpleExpr', $children);
        } elseif ($this->isSimpleExprStart($token) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::CONCAT_PIPES_SYMBOL) {
            $children[] = $this->simpleExpr();
            $children[] = $this->match(MySQLLexer::CONCAT_PIPES_SYMBOL);
            $children[] = $this->simpleExpr();
            return new ASTNode('simpleExpr', $children);
        } else {
            throw new \Exception('Unexpected token in simpleExpr: ' . $token->getText());
        }
    }

    public function identListArg()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            return $this->identListWithParentheses();
        } else {
            return $this->identList();
        }
    }

    public function identList()
    {
        $children = [];
        $children[] = $this->simpleIdentifier();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::COMMA_SYMBOL));
            $children[] = $this->simpleIdentifier();
        }
        return new ASTNode('identList', $children);
    }

    public function internalVariableName()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
            $children[] = $this->dotIdentifier();
        } else {
            $children[] = $this->identifier();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->dotIdentifier();
            }
        }

        return new ASTNode('internalVariableName', $children);
    }

    public function ternaryOption()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ULONGLONG_NUMBER) {
            return $this->ulonglong_number();
        } else {
            return $this->match(MySQLLexer::DEFAULT_SYMBOL);
        }
    }

    public function identListWithParentheses()
    {
        $children = [];

        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        $children[] = $this->identList();
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        return new ASTNode('identListWithParentheses', $children);
    }

    public function fulltextOptions()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::IN_SYMBOL) {
            $this->match(MySQLLexer::IN_SYMBOL);
            $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::IN_SYMBOL))];
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BOOLEAN_SYMBOL) {
                $this->match(MySQLLexer::BOOLEAN_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BOOLEAN_SYMBOL));
            } else {
                $this->match(MySQLLexer::NATURAL_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NATURAL_SYMBOL));
            }
            $this->match(MySQLLexer::LANGUAGE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::LANGUAGE_SYMBOL));
            $this->match(MySQLLexer::MODE_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::MODE_SYMBOL));
            return new ASTNode('fulltextOptions', $children);
        } else {
            $this->match(MySQLLexer::WITH_SYMBOL);
            $children = [new ASTNode(MySQLLexer::getTokenName(MySQLLexer::WITH_SYMBOL))];
            $this->match(MySQLLexer::QUERY_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::QUERY_SYMBOL));
            $this->match(MySQLLexer::EXPANSION_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::EXPANSION_SYMBOL));
            return new ASTNode('fulltextOptions', $children);
        }
    }

    public function arrayCast()
    {
        return $this->match(MySQLLexer::ARRAY_SYMBOL);
    }

    private function isRuntimeFunctionCallStart($token)
    {
        switch ($token->getType()) {
            case MySQLLexer::CHAR_SYMBOL:
            case MySQLLexer::CURRENT_USER_SYMBOL:
            case MySQLLexer::DATE_SYMBOL:
            case MySQLLexer::DAY_SYMBOL:
            case MySQLLexer::HOUR_SYMBOL:
            case MySQLLexer::INSERT_SYMBOL:
            case MySQLLexer::INTERVAL_SYMBOL:
            case MySQLLexer::LEFT_SYMBOL:
            case MySQLLexer::MICROSECOND_SYMBOL:
            case MySQLLexer::MINUTE_SYMBOL:
            case MySQLLexer::MONTH_SYMBOL:
            case MySQLLexer::RIGHT_SYMBOL:
            case MySQLLexer::SECOND_SYMBOL:
            case MySQLLexer::TIME_SYMBOL:
            case MySQLLexer::TIMESTAMP_SYMBOL:
            case MySQLLexer::TRIM_SYMBOL:
            case MySQLLexer::USER_SYMBOL:
            case MySQLLexer::VALUES_SYMBOL:
            case MySQLLexer::YEAR_SYMBOL:
            case MySQLLexer::ADDDATE_SYMBOL:
            case MySQLLexer::SUBDATE_SYMBOL:
            case MySQLLexer::CURDATE_SYMBOL:
            case MySQLLexer::CURTIME_SYMBOL:
            case MySQLLexer::DATE_ADD_SYMBOL:
            case MySQLLexer::DATE_SUB_SYMBOL:
            case MySQLLexer::EXTRACT_SYMBOL:
            case MySQLLexer::GET_FORMAT_SYMBOL:
            case MySQLLexer::NOW_SYMBOL:
            case MySQLLexer::POSITION_SYMBOL:
            case MySQLLexer::SUBSTR_SYMBOL:
            case MySQLLexer::SUBSTRING_SYMBOL:
            case MySQLLexer::SYSDATE_SYMBOL:
            case MySQLLexer::TIMESTAMP_ADD_SYMBOL:
            case MySQLLexer::TIMESTAMP_DIFF_SYMBOL:
            case MySQLLexer::UTC_DATE_SYMBOL:
            case MySQLLexer::UTC_TIME_SYMBOL:
            case MySQLLexer::UTC_TIMESTAMP_SYMBOL:
            case MySQLLexer::ASCII_SYMBOL:
            case MySQLLexer::CHARSET_SYMBOL:
            case MySQLLexer::COALESCE_SYMBOL:
            case MySQLLexer::COLLATION_SYMBOL:
            case MySQLLexer::CONCAT_SYMBOL:
            case MySQLLexer::DATABASE_SYMBOL:
            case MySQLLexer::IF_SYMBOL:
            case MySQLLexer::FORMAT_SYMBOL:
            case MySQLLexer::FOUND_ROWS_SYMBOL:
            case MySQLLexer::MOD_SYMBOL:
                return true;
            case MySQLLexer::OLD_PASSWORD_SYMBOL:
                return $this->serverVersion < 50607;
            case MySQLLexer::PASSWORD_SYMBOL:
                return $this->serverVersion < 80011;
            case MySQLLexer::QUARTER_SYMBOL:
            case MySQLLexer::REPEAT_SYMBOL:
            case MySQLLexer::REPLACE_SYMBOL:
            case MySQLLexer::REVERSE_SYMBOL:
            case MySQLLexer::ROW_COUNT_SYMBOL:
            case MySQLLexer::SCHEMA_SYMBOL:
            case MySQLLexer::SESSION_USER_SYMBOL:
            case MySQLLexer::SYSTEM_USER_SYMBOL:
            case MySQLLexer::TRUNCATE_SYMBOL:
            case MySQLLexer::WEEK_SYMBOL:
            case MySQLLexer::WEIGHT_STRING_SYMBOL:
                return true;
            case MySQLLexer::CONTAINS_SYMBOL:
                return $this->serverVersion < 50706;
            case MySQLLexer::GEOMETRYCOLLECTION_SYMBOL:
            case MySQLLexer::LINESTRING_SYMBOL:
            case MySQLLexer::MULTILINESTRING_SYMBOL:
            case MySQLLexer::MULTIPOINT_SYMBOL:
            case MySQLLexer::MULTIPOLYGON_SYMBOL:
            case MySQLLexer::POINT_SYMBOL:
            case MySQLLexer::POLYGON_SYMBOL:
                return true;
            default:
                return false;
        }
    }

    public function variable()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            return $this->userVariable();
        } elseif ($token->getType() === MySQLLexer::AT_AT_SIGN_SYMBOL) {
            return $this->systemVariable();
        } else {
            throw new \Exception('Unexpected token in variable: ' . $token->getText());
        }
    }

    public function whenExpression()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WHEN_SYMBOL);
        $children[] = $this->expr();

        return new ASTNode('whenExpression', $children);
    }

    public function thenExpression()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::THEN_SYMBOL);
        $children[] = $this->expr();

        return new ASTNode('thenExpression', $children);
    }

    public function elseExpression()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ELSE_SYMBOL);
        $children[] = $this->expr();

        return new ASTNode('elseExpression', $children);
    }

    public function castType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::BINARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
        } elseif ($token->getType() === MySQLLexer::CHAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }

            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::NCHAR_SYMBOL ||
                  $token->getType() === MySQLLexer::NATIONAL_SYMBOL) {
            $children[] = $this->nchar();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
        } elseif ($token->getType() === MySQLLexer::DATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::TIMESTAMP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIMESTAMP_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::DATETIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATETIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::DECIMAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DECIMAL_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->floatOptions();
            }
        } elseif ($this->serverVersion >= 50708 && $token->getType() === MySQLLexer::JSON_SYMBOL) {
            $children[] = $this->match(MySQLLexer::JSON_SYMBOL);
        } elseif ($this->serverVersion >= 80017 &&
                  ($token->getType() === MySQLLexer::REAL_SYMBOL ||
                   $token->getType() === MySQLLexer::DOUBLE_SYMBOL)) {
            $children[] = $this->realType();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->precision();
            }
        } elseif ($this->serverVersion >= 80017 && $token->getType() === MySQLLexer::FLOAT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FLOAT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->standardFloatOptions();
            }
        } elseif ($token->getType() === MySQLLexer::SIGNED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SIGNED_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INT_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::UNSIGNED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNSIGNED_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INT_SYMBOL);
            }
        } else {
            throw new \Exception('Unexpected token in castType: ' . $token->getText());
        }

        return new ASTNode('castType', $children);
    }

    private function isCharsetWithOptBinaryStart($token)
    {
        return $token->getType() === MySQLLexer::CHAR_SYMBOL ||
               $token->getType() === MySQLLexer::CHAR_SYMBOL ||
               $token->getType() === MySQLLexer::BINARY_SYMBOL ||
               $this->isAsciiStart($token) ||
               $this->isUnicodeStart($token) ||
               $this->isCharsetName($token);
    }

    private function isCharsetName($token)
    {
        switch($token->getType()) {
            case MySQLLexer::IDENTIFIER:
            case MySQLLexer::BACK_TICK_QUOTED_ID:
            case MySQLLexer::DOUBLE_QUOTED_TEXT:
            case MySQLLexer::SINGLE_QUOTED_TEXT:
                return true;
            case MySQLLexer::DEFAULT_SYMBOL:
                if ($this->serverVersion < 80011) {
                    return true;
                }
            case MySQLLexer::BINARY_SYMBOL:
                return true;
            default:
                return $this->isIdentifierKeyword($token);
        }
     }

    public function exprList()
    {
        $children = [];

        $children[] = $this->expr();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
        }
        return new ASTNode('exprList', $children);
    }


    public function likeClause()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::LIKE_SYMBOL);
        $children[] = $this->textStringLiteral();

        return new ASTNode('likeClause', $children);
    }
    public function createUserTail()
    {
        return $this->match(MySQLLexer::SESSION_SYMBOL);
    }

     public function jsonOperator()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::JSON_SEPARATOR_SYMBOL) {
            return $this->match(MySQLLexer::JSON_SEPARATOR_SYMBOL);
        } else {
            return $this->match(MySQLLexer::JSON_UNQUOTED_SEPARATOR_SYMBOL);
        }
    }
    public function replacePassword()
    {
        $children = [];

        $this->match(MySQLLexer::REPLACE_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::REPLACE_SYMBOL));
        $children[] = $this->textString();

        return new ASTNode('replacePassword', $children);
    }

    public function retainCurrentPassword()
    {
        return $this->match(MySQLLexer::RETAIN_SYMBOL);
    }

    public function discardOldPassword()
    {
        $children = [];

        $this->match(MySQLLexer::DISCARD_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DISCARD_SYMBOL));
        $this->match(MySQLLexer::OLD_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OLD_SYMBOL));
        $this->match(MySQLLexer::PASSWORD_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::PASSWORD_SYMBOL));

        return new ASTNode('discardOldPassword', $children);
    }
    public function aclType()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::TABLE_SYMBOL) {
            return $this->match(MySQLLexer::TABLE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::FUNCTION_SYMBOL) {
            return $this->match(MySQLLexer::FUNCTION_SYMBOL);
        } else {
            return $this->match(MySQLLexer::PROCEDURE_SYMBOL);
        }
    }

    public function charset()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::CHAR_SYMBOL) {
            $this->match(MySQLLexer::CHAR_SYMBOL);
            $children = [
                new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CHAR_SYMBOL)),
            ];
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            return new ASTNode('charset', $children);
        } elseif ($token->getType() === MySQLLexer::CHARSET_SYMBOL) {
            return $this->match(MySQLLexer::CHARSET_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in charset: ' . $token->getText());
        }
    }

    public function notRule()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::NOT_SYMBOL:
        case MySQLLexer::NOT2_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function not2Rule()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::LOGICAL_NOT_OPERATOR:
        case MySQLLexer::NOT2_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    // None of the microsecond variants can be used in schedules (e.g. events).
    public function interval()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isIntervalTimeStampStart($token)) {
            return $this->intervalTimeStamp();
        } elseif ($token->getType() === MySQLLexer::SECOND_MICROSECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::MINUTE_MICROSECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::MINUTE_SECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::HOUR_MICROSECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::HOUR_SECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::HOUR_MINUTE_SYMBOL ||
                  $token->getType() === MySQLLexer::DAY_MICROSECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::DAY_SECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::DAY_MINUTE_SYMBOL ||
                  $token->getType() === MySQLLexer::DAY_HOUR_SYMBOL ||
                  $token->getType() === MySQLLexer::YEAR_MONTH_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        } else {
            throw new \Exception('Unexpected token in interval: ' . $token->getText());
        }
    }

    // Support for SQL_TSI_* units is added by mapping those to tokens without SQL_TSI_ prefix.
    public function intervalTimeStamp()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::MICROSECOND_SYMBOL ||
            $token->getType() === MySQLLexer::SQL_TSI_MICROSECOND_SYMBOL) {
            return $this->match(MySQLLexer::MICROSECOND_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SECOND_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_SECOND_SYMBOL) {
            return $this->match(MySQLLexer::SECOND_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MINUTE_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_MINUTE_SYMBOL) {
            return $this->match(MySQLLexer::MINUTE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::HOUR_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_HOUR_SYMBOL) {
            return $this->match(MySQLLexer::HOUR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DAY_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_DAY_SYMBOL) {
            return $this->match(MySQLLexer::DAY_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::WEEK_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_WEEK_SYMBOL) {
            return $this->match(MySQLLexer::WEEK_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MONTH_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_MONTH_SYMBOL) {
            return $this->match(MySQLLexer::MONTH_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::QUARTER_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_QUARTER_SYMBOL) {
            return $this->match(MySQLLexer::QUARTER_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::YEAR_SYMBOL ||
                  $token->getType() === MySQLLexer::SQL_TSI_YEAR_SYMBOL) {
            return $this->match(MySQLLexer::YEAR_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in intervalTimeStamp: ' . $token->getText());
        }
    }

    public function exprListWithParentheses()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->exprList();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('exprListWithParentheses', $children);
    }

    public function exprWithParentheses()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->expr();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('exprWithParentheses', $children);
    }

    public function simpleExprWithParentheses()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->simpleExpr();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('simpleExprWithParentheses', $children);
    }

    public function functionCall()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($this->isQualifiedIdentifierStart($token)) {
            $children[] = $this->qualifiedIdentifier();
        } else {
            $children[] = $this->pureIdentifier();
        }
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        
        if ($this->isExprStart($this->lexer->peekNextToken())) {
            $children[] = $this->exprList();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('functionCall', $children);
    }

    public function orderList()
    {
        $children = [];
        $children[] = $this->orderExpression();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->orderExpression();
        }
        return new ASTNode('orderList', $children);
    }

    public function orderExpression()
    {
        $children = [];
        $children[] = $this->expr();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASC_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DESC_SYMBOL) {
            $children[] = $this->direction();
        }
        return new ASTNode('orderExpression', $children);
    }

    public function groupList()
    {
        $children = [];
        $children[] = $this->groupingExpression();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->groupingExpression();
        }

        return new ASTNode('groupList', $children);
    }

    public function groupingExpression()
    {
        return $this->expr();
    }

    //----------------- Stored routines rules ------------------------------------------------------------------------------

    // Compound syntax for stored procedures, stored functions, triggers and events.
    // Implements both, sp_proc_stmt and ev_sql_stmt_inner from the server grammar.
    public function compoundStatement()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isSimpleStatementStart($token) ||
            $token->getType() === MySQLLexer::BEGIN_SYMBOL) {
            return $this->simpleStatement();
        } elseif ($token->getType() === MySQLLexer::RETURN_SYMBOL) {
            return $this->returnStatement();
        } elseif ($token->getType() === MySQLLexer::DECLARE_SYMBOL) {
            return $this->spDeclarations();
        } elseif ($token->getType() === MySQLLexer::IF_SYMBOL) {
            return $this->ifStatement();
        } elseif ($token->getType() === MySQLLexer::CASE_SYMBOL) {
            return $this->caseStatement();
        } elseif (($token->getType() === MySQLLexer::IDENTIFIER ||
                   $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isLabelKeyword($token)) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::COLON_SYMBOL) {
            return $this->labeledBlock();
        } elseif ($token->getType() === MySQLLexer::BEGIN_SYMBOL) {
            return $this->unlabeledBlock();
        } elseif (($token->getType() === MySQLLexer::IDENTIFIER ||
                   $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isLabelKeyword($token)) &&
                  ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::LOOP_SYMBOL ||
                   $this->lexer->peekNextToken(2)->getType() === MySQLLexer::REPEAT_SYMBOL ||
                   $this->lexer->peekNextToken(2)->getType() === MySQLLexer::WHILE_SYMBOL)) {
            return $this->labeledControl();
       } elseif ($token->getType() === MySQLLexer::LOOP_SYMBOL ||
                  $token->getType() === MySQLLexer::REPEAT_SYMBOL ||
                  $token->getType() === MySQLLexer::WHILE_SYMBOL) {
            return $this->unlabeledControl();
        } elseif ($token->getType() === MySQLLexer::LEAVE_SYMBOL) {
            return $this->leaveStatement();
        } elseif ($token->getType() === MySQLLexer::ITERATE_SYMBOL) {
            return $this->iterateStatement();
        } elseif ($token->getType() === MySQLLexer::OPEN_SYMBOL) {
            return $this->cursorOpen();
        } elseif ($token->getType() === MySQLLexer::FETCH_SYMBOL) {
            return $this->cursorFetch();
        } elseif ($token->getType() === MySQLLexer::CLOSE_SYMBOL) {
            return $this->cursorClose();
        } else {
            throw new \Exception('Unexpected token in compoundStatement: ' . $token->getText());
        }
    }

    public function returnStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::RETURN_SYMBOL);
        $children[] = $this->expr();
        return new ASTNode('returnStatement', $children);
    }

    public function ifStatement()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::IF_SYMBOL);
        $children[] = $this->ifBody();
        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        $children[] = $this->match(MySQLLexer::IF_SYMBOL);
        return new ASTNode('ifStatement', $children);
    }

    public function ifBody()
    {
        $children = [];
        $children[] = $this->expr();
        $children[] = $this->thenStatement();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ELSEIF_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ELSEIF_SYMBOL);
            $children[] = $this->ifBody();
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::ELSE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ELSE_SYMBOL);
            $children[] = $this->compoundStatementList();
        }

        return new ASTNode('ifBody', $children);
    }

    public function thenStatement()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::THEN_SYMBOL);
        $children[] = $this->compoundStatementList();
        return new ASTNode('thenStatement', $children);
    }

    public function compoundStatementList()
    {
        $children = [];
        do {
            $children[] = $this->compoundStatement();
            $children[] = $this->match(MySQLLexer::SEMICOLON_SYMBOL);
        } while ($this->isCompoundStatementStart($this->lexer->peekNextToken()));
        return new ASTNode('compoundStatementList', $children);
    }

    public function caseStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CASE_SYMBOL);
        if ($this->isBoolPriStart($this->lexer->peekNextToken())) {
            $children[] = $this->expr();
        }
        do {
            $children[] = $this->whenExpression();
            $children[] = $this->thenStatement();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHEN_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ELSE_SYMBOL) {
            $children[] = $this->elseStatement();
        }
        
        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        $children[] = $this->match(MySQLLexer::CASE_SYMBOL);
        
        return new ASTNode('caseStatement', $children);
    }

    public function labeledBlock()
    {
        $children = [];

        $children[] = $this->label();
        $children[] = $this->beginEndBlock();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isLabelKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->labelRef();
        }

        return new ASTNode('labeledBlock', $children);
    }

    public function unlabeledBlock()
    {
        return $this->beginEndBlock();
    }

    public function label()
    {
        $children = [];
        $children[] = $this->labelIdentifier();
        $children[] = $this->match(MySQLLexer::COLON_SYMBOL);
        return new ASTNode('label', $children);
    }

    public function beginEndBlock()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::BEGIN_SYMBOL);
        
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DECLARE_SYMBOL) {
            $children[] = $this->spDeclarations();
        }

        if ($this->isCompoundStatementStart($this->lexer->peekNextToken())) {
            $children[] = $this->compoundStatementList();
        }

        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        return new ASTNode('beginEndBlock', $children);
    }

    public function labeledControl()
    {
        $children = [];
        $children[] = $this->label();
        $children[] = $this->unlabeledControl();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isLabelKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->labelRef();
        }
        return new ASTNode('labeledControl', $children);
    }

    public function unlabeledControl()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::LOOP_SYMBOL) {
            return $this->loopBlock();
        } elseif ($token->getType() === MySQLLexer::WHILE_SYMBOL) {
            return $this->whileDoBlock();
        } elseif ($token->getType() === MySQLLexer::REPEAT_SYMBOL) {
            return $this->repeatUntilBlock();
        } else {
            throw new \Exception('Unexpected token in unlabeledControl: ' . $token->getText());
        }
    }

    public function loopBlock()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LOOP_SYMBOL);
        $children[] = $this->compoundStatementList();
        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        $children[] = $this->match(MySQLLexer::LOOP_SYMBOL);
        return new ASTNode('loopBlock', $children);
    }

    public function whileDoBlock()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::WHILE_SYMBOL);
        $children[] = $this->expr();
        $children[] = $this->match(MySQLLexer::DO_SYMBOL);
        $children[] = $this->compoundStatementList();
        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        $children[] = $this->match(MySQLLexer::WHILE_SYMBOL);
        return new ASTNode('whileDoBlock', $children);
    }

    public function repeatUntilBlock()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REPEAT_SYMBOL);
        $children[] = $this->compoundStatementList();
        $children[] = $this->match(MySQLLexer::UNTIL_SYMBOL);
        $children[] = $this->expr();
        $children[] = $this->match(MySQLLexer::END_SYMBOL);
        $children[] = $this->match(MySQLLexer::REPEAT_SYMBOL);

        return new ASTNode('repeatUntilBlock', $children);
    }

    public function spDeclarations()
    {
        $children = [];
        do {
            $children[] = $this->spDeclaration();
            $children[] = $this->match(MySQLLexer::SEMICOLON_SYMBOL);
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::DECLARE_SYMBOL);
        return new ASTNode('spDeclarations', $children);
    }

    public function spDeclaration()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::DECLARE_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            $children[] = $this->variableDeclaration();
        } elseif ($token->getType() === MySQLLexer::CONDITION_SYMBOL) {
            $children[] = $this->conditionDeclaration();
        } elseif ($token->getType() === MySQLLexer::CONTINUE_SYMBOL ||
                  $token->getType() === MySQLLexer::EXIT_SYMBOL ||
                  $token->getType() === MySQLLexer::UNDO_SYMBOL) {
            $children[] = $this->handlerDeclaration();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token)) {
            $children[] = $this->cursorDeclaration();
        } else {
            throw new \Exception('Unexpected token in spDeclaration: ' . $token->getText());
        }
        return new ASTNode('spDeclaration', $children);
    }

    public function variableDeclaration()
    {
        $children = [];

        $children[] = $this->identifierList();
        $children[] = $this->dataType();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL) {
            $children[] = $this->collate();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            $children[] = $this->expr();
        }
        return new ASTNode('variableDeclaration', $children);
    }

    public function conditionDeclaration()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CONDITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
        $children[] = $this->spCondition();

        return new ASTNode('conditionDeclaration', $children);
    }

    public function spCondition()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ULONGLONG_NUMBER ||
            $token->getType() === MySQLLexer::LONG_NUMBER ||
            $token->getType() === MySQLLexer::INT_NUMBER) {
            return $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::SQLSTATE_SYMBOL) {
            return $this->sqlstate();
        } else {
            throw new \Exception('Unexpected token in spCondition: ' . $token->getText());
        }
    }

    public function sqlstate()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SQLSTATE_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VALUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VALUE_SYMBOL);
        }
        $children[] = $this->textLiteral();

        return new ASTNode('sqlstate', $children);
    }

    public function handlerDeclaration()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::CONTINUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONTINUE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::EXIT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EXIT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::UNDO_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNDO_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in handlerDeclaration: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::HANDLER_SYMBOL);
        $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
        $children[] = $this->handlerCondition();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->handlerCondition();
        }

        $children[] = $this->compoundStatement();
        return new ASTNode('handlerDeclaration', $children);
    }

    public function handlerCondition()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::SQLSTATE_SYMBOL ||
            $this->isUlong_numberStart($token)) {
            return $this->spCondition();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token)) {
            return $this->identifier();
        } elseif ($token->getType() === MySQLLexer::SQLWARNING_SYMBOL) {
            return $this->match(MySQLLexer::SQLWARNING_SYMBOL);
        } elseif ($token->getText() === 'NOT FOUND') {
            $children = [];
            $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
            $children[] = $this->match(MySQLLexer::FOUND_SYMBOL);
            return new ASTNode('handlerCondition', $children);
        } elseif ($token->getType() === MySQLLexer::SQLEXCEPTION_SYMBOL) {
            return $this->match(MySQLLexer::SQLEXCEPTION_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in handlerCondition: ' . $token->getText());
        }
    }

    public function cursorDeclaration()
    {
        $children = [];
        $children[] = $this->identifier();
        $children[] = $this->match(MySQLLexer::CURSOR_SYMBOL);
        $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
        $children[] = $this->selectStatement();
        return new ASTNode('cursorDeclaration', $children);
    }

    public function iterateStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ITERATE_SYMBOL);
        $children[] = $this->labelRef();
        return new ASTNode('iterateStatement', $children);
    }

    public function leaveStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::LEAVE_SYMBOL);
        $children[] = $this->labelRef();
        return new ASTNode('leaveStatement', $children);
    }

    public function getDiagnostics()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::GET_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CURRENT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::STACKED_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CURRENT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CURRENT_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::STACKED_SYMBOL);
            }
        }
        $children[] = $this->match(MySQLLexer::DIAGNOSTICS_SYMBOL);
        $token = $this->lexer->peekNextToken();
        if (($token->getType() === MySQLLexer::IDENTIFIER ||
             $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
             $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
             $this->isIdentifierKeyword($token) ||
             $token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
             $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) &&
            $this->lexer->peekNextToken(2)->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $children[] = $this->statementInformationItem();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->statementInformationItem();
            }
        } elseif ($token->getType() === MySQLLexer::CONDITION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONDITION_SYMBOL);
            $children[] = $this->signalAllowedExpr();
            $children[] = $this->conditionInformationItem();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->conditionInformationItem();
            }
        } else {
            throw new \Exception('Unexpected token in getDiagnostics: ' . $token->getText());
        }
        return new ASTNode('getDiagnostics', $children);
    }

    // Only a limited subset of expr is allowed in SIGNAL/RESIGNAL/CONDITIONS.
    public function signalAllowedExpr()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isLiteralStart($token)) {
            return $this->literal();
        } elseif ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
                  $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            return $this->variable();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token) ||
                  $token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->qualifiedIdentifier();
        } else {
            throw new \Exception('Unexpected token in signalAllowedExpr: ' . $token->getText());
        }
    }

    public function statementInformationItem()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->variable();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token)) {
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in statementInformationItem: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NUMBER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NUMBER_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::ROW_COUNT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROW_COUNT_SYMBOL);
        } else {
            throw new \Exception(
                'Unexpected token in statementInformationItem: ' . $this->lexer->peekNextToken()->getText()
            );
        }
        return new ASTNode('statementInformationItem', $children);
    }

    public function conditionInformationItem()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::AT_SIGN_SYMBOL ||
            $token->getType() === MySQLLexer::AT_TEXT_SUFFIX) {
            $children[] = $this->variable();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token)) {
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in conditionInformationItem: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::CLASS_ORIGIN_SYMBOL ||
            $token->getType() === MySQLLexer::SUBCLASS_ORIGIN_SYMBOL ||
            $token->getType() === MySQLLexer::CONSTRAINT_CATALOG_SYMBOL ||
            $token->getType() === MySQLLexer::CONSTRAINT_SCHEMA_SYMBOL ||
            $token->getType() === MySQLLexer::CONSTRAINT_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::CATALOG_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::SCHEMA_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::TABLE_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::COLUMN_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::CURSOR_NAME_SYMBOL ||
            $token->getType() === MySQLLexer::MESSAGE_TEXT_SYMBOL ||
            $token->getType() === MySQLLexer::MYSQL_ERRNO_SYMBOL) {
            $children[] = $this->signalInformationItemName();
        } elseif ($token->getType() === MySQLLexer::RETURNED_SQLSTATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RETURNED_SQLSTATE_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in conditionInformationItem: ' . $token->getText());
        }

        return new ASTNode('conditionInformationItem', $children);
    }

    public function signalStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SIGNAL_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            $children[] = $this->identifier();
        } elseif ($token->getType() === MySQLLexer::SQLSTATE_SYMBOL) {
            $children[] = $this->sqlstate();
        } else {
            throw new \Exception('Unexpected token in signalStatement: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->signalInformationItem();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->signalInformationItem();
            }
        }

        return new ASTNode('signalStatement', $children);
    }

    public function resignalStatement()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::RESIGNAL_SYMBOL);

        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::SQLSTATE_SYMBOL) {
            if ($token->getType() === MySQLLexer::IDENTIFIER ||
                $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($token)) {
                $children[] = $this->identifier();
            } else {
                $children[] = $this->sqlstate();
            }
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->signalInformationItem();

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->signalInformationItem();
            }
        }

        return new ASTNode('resignalStatement', $children);
    }

    public function signalInformationItem()
    {
        $children = [];

        $children[] = $this->signalInformationItemName();
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        $children[] = $this->signalAllowedExpr();
        return new ASTNode('signalInformationItem', $children);
    }

    public function signalInformationItemName()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::CLASS_ORIGIN_SYMBOL:
        case MySQLLexer::SUBCLASS_ORIGIN_SYMBOL:
        case MySQLLexer::CONSTRAINT_CATALOG_SYMBOL:
        case MySQLLexer::CONSTRAINT_SCHEMA_SYMBOL:
        case MySQLLexer::CONSTRAINT_NAME_SYMBOL:
        case MySQLLexer::CATALOG_NAME_SYMBOL:
        case MySQLLexer::SCHEMA_NAME_SYMBOL:
        case MySQLLexer::TABLE_NAME_SYMBOL:
        case MySQLLexer::COLUMN_NAME_SYMBOL:
        case MySQLLexer::CURSOR_NAME_SYMBOL:
        case MySQLLexer::MESSAGE_TEXT_SYMBOL:
        case MySQLLexer::MYSQL_ERRNO_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function cursorOpen()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_SYMBOL);
        $children[] = $this->identifier();
        return new ASTNode('cursorOpen', $children);
    }

    public function cursorFetch()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::FETCH_SYMBOL);
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NEXT_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::FROM_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NEXT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NEXT_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
        }
        $children[] = $this->identifier();
        $children[] = $this->match(MySQLLexer::INTO_SYMBOL);
        $children[] = $this->identifierList();
        return new ASTNode('cursorFetch', $children);
    }

    public function cursorClose()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CLOSE_SYMBOL);
        $children[] = $this->identifier();
        return new ASTNode('cursorClose', $children);
    }

    //----------------- Supplemental rules ---------------------------------------------------------------------------------

    // Schedules in CREATE/ALTER EVENT.
    public function schedule()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::AT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AT_SYMBOL);
            $children[] = $this->expr();
        } elseif ($token->getType() === MySQLLexer::EVERY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EVERY_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->interval();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STARTS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::STARTS_SYMBOL);
                $children[] = $this->expr();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENDS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::ENDS_SYMBOL);
                $children[] = $this->expr();
            }
        } else {
            throw new \Exception('Unexpected token in schedule: ' . $token->getText());
        }

        return new ASTNode('schedule', $children);
    }

    public function columnDefinition()
    {
        $children = [];
        $children[] = $this->columnName();
        $children[] = $this->fieldDefinition();
        if ($this->serverVersion < 80016 &&
            ($this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::REFERENCES_SYMBOL)) {
            $children[] = $this->checkOrReferences();
        }

        return new ASTNode('columnDefinition', $children);
    }

    public function checkOrReferences()
    {
        if ($this->serverVersion < 80016) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::CHECK_SYMBOL) {
                return $this->checkConstraint();
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::REFERENCES_SYMBOL) {
                return $this->references();
            } else {
                throw new \Exception('Unexpected token in checkOrReferences: ' . $this->lexer->peekNextToken()->getText());
            }
        } else {
            return $this->references();
        }
    }

    public function constraintEnforcement()
    {
        $children = [];
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
        }
        $children[] = $this->match(MySQLLexer::ENFORCED_SYMBOL);

        return new ASTNode('constraintEnforcement', $children);
    }

    public function tableConstraintDef()
    {
        $children = [];
        $token1 = $this->lexer->peekNextToken();
        $token2 = $this->lexer->peekNextToken(2);

        if ($token1->getType() === MySQLLexer::CONSTRAINT_SYMBOL) {
            $children[] = $this->constraintName();
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                $token->getType() === MySQLLexer::UNIQUE_SYMBOL) {
                $children[] = $this->constraintKeyType();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL) {
                    $children[] = $this->indexNameAndType();
                }
                $children[] = $this->keyListVariants();

                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                    $children[] = $this->indexOption();
                }
            } elseif ($token->getType() === MySQLLexer::FOREIGN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FOREIGN_SYMBOL);
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                    $children[] = $this->indexName();
                }

                $children[] = $this->keyList();
                $children[] = $this->references();
            } elseif ($token->getType() === MySQLLexer::CHECK_SYMBOL) {
                $children[] = $this->checkConstraint();
                if ($this->serverVersion >= 80017 &&
                    ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL ||
                     $this->lexer->peekNextToken()->getType() === MySQLLexer::ENFORCED_SYMBOL)) {
                    $children[] = $this->constraintEnforcement();
                }
            } else {
                throw new \Exception('Unexpected token in tableConstraintDef: ' . $token->getText());
            }
        } elseif ($token1->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                  $token1->getType() === MySQLLexer::UNIQUE_SYMBOL) {
            $children[] = $this->constraintKeyType();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL) {
                $children[] = $this->indexNameAndType();
            }
            $children[] = $this->keyListVariants();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->indexOption();
            }
        } elseif ($token1->getType() === MySQLLexer::FOREIGN_SYMBOL && $token2->getType() === MySQLLexer::KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FOREIGN_SYMBOL);
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->indexName();
            }

            $children[] = $this->keyList();
            $children[] = $this->references();
        } elseif ($token1->getType() === MySQLLexer::CHECK_SYMBOL) {
            $children[] = $this->checkConstraint();
            if ($this->serverVersion >= 80017 &&
                ($this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ENFORCED_SYMBOL)) {
                $children[] = $this->constraintEnforcement();
            }
        } elseif (($token1->getType() === MySQLLexer::KEY_SYMBOL || $token1->getType() === MySQLLexer::INDEX_SYMBOL) &&
                  ($token2->getType() === MySQLLexer::IDENTIFIER ||
                   $token2->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                   $token2->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                   $this->isIdentifierKeyword($token2) ||
                   $token2->getType() === MySQLLexer::USING_SYMBOL ||
                   $token2->getType() === MySQLLexer::TYPE_SYMBOL ||
                   $token2->getType() === MySQLLexer::OPEN_PAR_SYMBOL)) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken()) ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL) {
                $children[] = $this->indexNameAndType();
            }

            $children[] = $this->keyListVariants();

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::TYPE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->indexOption();
            }
        } elseif ($token1->getType() === MySQLLexer::FULLTEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FULLTEXT_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                }
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->indexName();
            }

            $children[] = $this->keyListVariants();

            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->fulltextIndexOption();
            }
        } elseif ($token1->getType() === MySQLLexer::SPATIAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SPATIAL_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                }
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->indexName();
            }
            $children[] = $this->keyListVariants();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $this->lexer->peekNextToken()->getType() === MySQLLexer::INVISIBLE_SYMBOL) {
                $children[] = $this->spatialIndexOption();
            }
        } else {
            throw new \Exception('Unexpected token in tableConstraintDef: ' . $token1->getText());
        }
        return new ASTNode('tableConstraintDef', $children);
    }

    public function constraintName()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CONSTRAINT_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->identifier();
        }

        return new ASTNode('constraintName', $children);
    }

    public function fieldDefinition()
    {
        $children = [];
        $children[] = $this->dataType();

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::COLLATE_SYMBOL) {
            $children[] = $this->collate();

            if ($this->serverVersion >= 50707 && $this->lexer->peekNextToken()->getType() === MySQLLexer::GENERATED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::GENERATED_SYMBOL);
                $children[] = $this->match(MySQLLexer::ALWAYS_SYMBOL);
            }

            $children[] = $this->match(MySQLLexer::AS_SYMBOL);
            $children[] = $this->exprWithParentheses();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VIRTUAL_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::STORED_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VIRTUAL_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::VIRTUAL_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::STORED_SYMBOL);
                }
            }

            if ($this->serverVersion < 80000) {
                while ($this->lexer->peekNextToken()->getType() === MySQLLexer::UNIQUE_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::NOT_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::NULL_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                       $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $children[] = $this->gcolAttribute();
                }
            } elseif ($this->isColumnAttributeStart($this->lexer->peekNextToken())) {
                do {
                    $children[] = $this->columnAttribute();
                } while ($this->isColumnAttributeStart($this->lexer->peekNextToken()));
            }
        } elseif ($this->isColumnAttributeStart($token)) {
            do {
                $children[] = $this->columnAttribute();
            } while ($this->isColumnAttributeStart($this->lexer->peekNextToken()));
        }

        return new ASTNode('fieldDefinition', $children);
    }
    public function columnAttribute()
    {
        $children = [];
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::NOT_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $token = $this->lexer->getNextToken();
                switch ($token->getType()) {
                    case MySQLLexer::NULL_SYMBOL:
                        $children[] = ASTNode::fromToken($token);
                        break;
                    case MySQLLexer::SECONDARY_SYMBOL:
                        if ($this->serverVersion >= 80014) {
                            $children[] = ASTNode::fromToken($token);
                            break;
                        }
                    default:
                        throw new \Exception('Unexpected token in columnAttribute: ' . $token->getText());
                }
                break;
            case MySQLLexer::NULL_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            case MySQLLexer::DEFAULT_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $token = $this->lexer->peekNextToken();
                if ($this->isSignedLiteralStart($token)) {
                    $children[] = $this->signedLiteral();
                } elseif ($token->getType() === MySQLLexer::NOW_SYMBOL) {
                    $this->match(MySQLLexer::NOW_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NOW_SYMBOL));
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                        $children[] = $this->timeFunctionParameters();
                    }
                } elseif ($this->serverVersion >= 80013 && $token->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->exprWithParentheses();
                } else {
                    throw new \Exception('Unexpected token in columnAttribute: ' . $token->getText());
                }
                break;
            case MySQLLexer::ON_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $this->match(MySQLLexer::UPDATE_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::UPDATE_SYMBOL));
                $this->match(MySQLLexer::NOW_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::NOW_SYMBOL));
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->timeFunctionParameters();
                }
                break;
            case MySQLLexer::AUTO_INCREMENT_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            case MySQLLexer::SERIAL_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $this->match(MySQLLexer::DEFAULT_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::DEFAULT_SYMBOL));
                $this->match(MySQLLexer::VALUE_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::VALUE_SYMBOL));
                break;
            case MySQLLexer::PRIMARY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $token = $this->lexer->getNextToken();
                switch ($token->getType()) {
                    case MySQLLexer::KEY_SYMBOL:
                        $children[] = ASTNode::fromToken($token);
                        break;
                    default:
                        throw new \Exception('Unexpected token in columnAttribute: ' . $token->getText());
                }
                break;
            case MySQLLexer::KEY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            case MySQLLexer::UNIQUE_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $this->match(MySQLLexer::KEY_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::KEY_SYMBOL));
                }
                break;
            case MySQLLexer::COMMENT_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->textLiteral();
                break;
            case MySQLLexer::COLLATE_SYMBOL:
                $children[] = $this->collate();
                break;
            case MySQLLexer::COLUMN_FORMAT_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->columnFormat();
                break;
            case MySQLLexer::STORAGE_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->storageMedia();
                break;
            case MySQLLexer::SRID_SYMBOL:
                if ($this->serverVersion >= 80000) {
                    $children[] = ASTNode::fromToken($token);
                    $children[] = $this->real_ulonglong_number();
                    break;
                }
            case MySQLLexer::CONSTRAINT_SYMBOL:
                if ($this->serverVersion >= 80017) {
                    $children[] = $this->constraintName();
                    $children[] = $this->checkConstraint();
                    break;
                }
            case MySQLLexer::ENFORCED_SYMBOL:
                if ($this->serverVersion >= 80017) {
                    $children[] = $this->constraintEnforcement();
                    break;
                }
            default:
                throw new \Exception('Unexpected token in columnAttribute: ' . $token->getText());
        }

        return new ASTNode('columnAttribute', $children);
     }

    private function isColumnAttributeStart($token)
    {
        return $token->getType() === MySQLLexer::NOT_SYMBOL ||
               $token->getType() === MySQLLexer::NULL_SYMBOL ||
               ($this->serverVersion >= 80014 && $token->getText() === 'NOT SECONDARY') ||
               $token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
               $token->getText() === 'ON UPDATE' ||
               $token->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL ||
               $token->getText() === 'SERIAL DEFAULT VALUE' ||
               $token->getType() === MySQLLexer::PRIMARY_SYMBOL ||
               $token->getType() === MySQLLexer::KEY_SYMBOL ||
               $token->getType() === MySQLLexer::UNIQUE_SYMBOL ||
               $token->getType() === MySQLLexer::COMMENT_SYMBOL ||
               $token->getType() === MySQLLexer::COLLATE_SYMBOL ||
               $token->getType() === MySQLLexer::COLUMN_FORMAT_SYMBOL ||
               $token->getType() === MySQLLexer::STORAGE_SYMBOL ||
               ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::SRID_SYMBOL) ||
               ($this->serverVersion >= 80017 &&
                ($token->getType() === MySQLLexer::CONSTRAINT_SYMBOL ||
                 $token->getType() === MySQLLexer::ENFORCED_SYMBOL));
    }

    public function references()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::REFERENCES_SYMBOL);
        $children[] = $this->tableRef();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->identifierListWithParentheses();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::MATCH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MATCH_SYMBOL);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::FULL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FULL_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::PARTIAL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PARTIAL_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::SIMPLE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::SIMPLE_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in references: ' . $token->getText());
            }
        }

        $token = $this->lexer->peekNextToken(2);

        if ($token->getType() === MySQLLexer::UPDATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
            $children[] = $this->deleteOption();
            if ($this->lexer->peekNextToken()->getText() === 'ON DELETE') {
                $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                $children[] = $this->match(MySQLLexer::DELETE_SYMBOL);
                $children[] = $this->deleteOption();
            }
        } elseif ($token->getType() === MySQLLexer::DELETE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ON_SYMBOL);
            $children[] = $this->match(MySQLLexer::DELETE_SYMBOL);
            $children[] = $this->deleteOption();
            if ($this->lexer->peekNextToken()->getText() === 'ON UPDATE') {
                $children[] = $this->match(MySQLLexer::ON_SYMBOL);
                $children[] = $this->match(MySQLLexer::UPDATE_SYMBOL);
                $children[] = $this->deleteOption();
            }
        }

        return new ASTNode('references', $children);
    }

    public function keyList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->keyPart();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->keyPart();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('keyList', $children);
    }

    public function keyPart()
    {
        $children = [];
        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->fieldLength();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASC_SYMBOL ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DESC_SYMBOL) {
            $children[] = $this->direction();
        }
        return new ASTNode('keyPart', $children);
    }

    public function keyListWithExpression()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->keyPartOrExpression();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->keyPartOrExpression();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('keyListWithExpression', $children);
    }

    public function keyPartOrExpression()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->exprWithParentheses();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::ASC_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DESC_SYMBOL) {
                $children[] = $this->direction();
            }
        } elseif ($this->isIdentifierStart($this->lexer->peekNextToken())) {
            $children[] = $this->keyPart();
        } else {
            throw new \Exception('Unexpected token in keyPartOrExpression: ' . $this->lexer->peekNextToken()->getText());
        }

        return new ASTNode('keyPartOrExpression', $children);
    }

    public function keyListVariants()
    {
        if ($this->serverVersion >= 80013) {
            return $this->keyListWithExpression();
        } else {
            return $this->keyList();
        }
    }

    public function indexType()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::BTREE_SYMBOL:
        case MySQLLexer::HASH_SYMBOL:
        case MySQLLexer::RTREE_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function checkOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::UPGRADE_SYMBOL);
            return new ASTNode('checkOption', $children);
        } elseif ($token->getType() === MySQLLexer::QUICK_SYMBOL) {
            return $this->match(MySQLLexer::QUICK_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::FAST_SYMBOL) {
            return $this->match(MySQLLexer::FAST_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MEDIUM_SYMBOL) {
            return $this->match(MySQLLexer::MEDIUM_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::EXTENDED_SYMBOL) {
            return $this->match(MySQLLexer::EXTENDED_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CHANGED_SYMBOL) {
            return $this->match(MySQLLexer::CHANGED_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in checkOption: ' . $token->getText());
        }
    }

    public function runtimeFunctionCall()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::CHAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->exprList();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::USING_SYMBOL) {
                $children[] = $this->match(MySQLLexer::USING_SYMBOL);
                $children[] = $this->charsetName();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::CURRENT_USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CURRENT_USER_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->parentheses();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::DATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATE_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::DAY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DAY_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::HOUR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::HOUR_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::INSERT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSERT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::INTERVAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->expr();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::LEFT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LEFT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::MICROSECOND_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MICROSECOND_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::MINUTE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MINUTE_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::MONTH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MONTH_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::RIGHT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::RIGHT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::SECOND_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SECOND_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::TIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIME_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::TIMESTAMP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIMESTAMP_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->expr();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::TRIM_SYMBOL) {
            return $this->trimFunction();
        } elseif ($token->getType() === MySQLLexer::USER_SYMBOL) {
            $children[] = $this->match(MySQLLexer::USER_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->parentheses();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::VALUES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VALUES_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::YEAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::YEAR_SYMBOL);
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::ADDDATE_SYMBOL ||
                  $token->getType() === MySQLLexer::SUBDATE_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::INTERVAL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
                $children[] = $this->expr();
                $children[] = $this->interval();
            } else {
                $children[] = $this->expr();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::CURDATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CURDATE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->parentheses();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::CURTIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CURTIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->timeFunctionParameters();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::DATE_ADD_SYMBOL ||
                  $token->getType() === MySQLLexer::DATE_SUB_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->match(MySQLLexer::INTERVAL_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->interval();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::EXTRACT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::EXTRACT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->interval();
            $children[] = $this->match(MySQLLexer::FROM_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::GET_FORMAT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::GET_FORMAT_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->dateTimeTtype();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::NOW_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NOW_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->timeFunctionParameters();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::POSITION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::POSITION_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->bitExpr();
            $children[] = $this->match(MySQLLexer::IN_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::SUBSTRING_SYMBOL ||
                  $token->getType() === MySQLLexer::SUBSTR_SYMBOL) {
            return $this->substringFunction();
        } elseif ($token->getType() === MySQLLexer::SYSDATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SYSDATE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->timeFunctionParameters();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::TIMESTAMP_ADD_SYMBOL ||
                  $token->getType() === MySQLLexer::TIMESTAMP_DIFF_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->intervalTimeStamp();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::UTC_DATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UTC_DATE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->parentheses();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::UTC_TIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UTC_TIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->timeFunctionParameters();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($token->getType() === MySQLLexer::UTC_TIMESTAMP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UTC_TIMESTAMP_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->timeFunctionParameters();
            }
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($this->isRuntimeFunctionCallStart($token)) {
            $children[] = $this->identifierKeyword();
            $children[] = $this->exprWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($this->isRuntimeFunctionCallStart($token)) {
            $children[] = $this->identifierKeyword();
            $children[] = $this->exprListWithParentheses();
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($this->isRuntimeFunctionCallStart($token)) {
            $children[] = $this->identifierKeyword();
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->expr();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } elseif ($this->isRuntimeFunctionCallStart($token)) {
            $children[] = $this->identifierKeyword();
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->expr();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->expr();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('runtimeFunctionCall', $children);
        } else {
            throw new \Exception('Unexpected token in runtimeFunctionCall: ' . $token->getText());
        }
    }

    
    public function repairType()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::QUICK_SYMBOL:
        case MySQLLexer::EXTENDED_SYMBOL:
        case MySQLLexer::USE_FRM_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    // Partition rules for CREATE/ALTER TABLE.
    public function partitionClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        $children[] = $this->partitionTypeDef();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PARTITIONS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PARTITIONS_SYMBOL);
            $children[] = $this->real_ulong_number();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SUBPARTITION_SYMBOL) {
            $children[] = $this->subPartitions();
        }
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->partitionDefinitions();
        }

        return new ASTNode('partitionClause', $children);
    }

    public function partitionTypeDef()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::LINEAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LINEAR_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
                if ($this->serverVersion >= 50700 && $this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                    $children[] = $this->partitionKeyAlgorithm();
                }
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                    $children[] = $this->identifierList();
                }
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
                return new ASTNode('partitionTypeDef', $children);
            } elseif ($token->getType() === MySQLLexer::HASH_SYMBOL) {
                $children[] = $this->match(MySQLLexer::HASH_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = $this->bitExpr();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
                return new ASTNode('partitionTypeDef', $children);
            } else {
                throw new \Exception('Unexpected token in partitionTypeDef: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            if ($this->serverVersion >= 50700 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                $children[] = $this->partitionKeyAlgorithm();
            }
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                $children[] = $this->identifierList();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('partitionTypeDef', $children);
        } elseif ($token->getType() === MySQLLexer::HASH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::HASH_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->bitExpr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('partitionTypeDef', $children);
        } elseif ($token->getType() === MySQLLexer::RANGE_SYMBOL ||
                  $token->getType() === MySQLLexer::LIST_SYMBOL) {
            if ($token->getType() === MySQLLexer::RANGE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::RANGE_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::LIST_SYMBOL);
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLUMNS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COLUMNS_SYMBOL);
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                    $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                    $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
                    $children[] = $this->identifierList();
                }
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
                $children[] = $this->bitExpr();
                $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            }

            return new ASTNode('partitionTypeDef', $children);
        } else {
            throw new \Exception('Unexpected token in partitionTypeDef: ' . $token->getText());
        }
    }

    public function subPartitions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SUBPARTITION_SYMBOL);
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LINEAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LINEAR_SYMBOL);
        }

        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::HASH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::HASH_SYMBOL);
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->bitExpr();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::KEY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);

            if ($this->serverVersion >= 50700 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ALGORITHM_SYMBOL) {
                $children[] = $this->partitionKeyAlgorithm();
            }

            $children[] = $this->identifierListWithParentheses();
        } else {
            throw new \Exception('Unexpected token in subPartitions: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SUBPARTITIONS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SUBPARTITIONS_SYMBOL);
            $children[] = $this->real_ulong_number();
        }

        return new ASTNode('subPartitions', $children);
    }

    public function partitionKeyAlgorithm()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::ALGORITHM_SYMBOL);
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        $children[] = $this->real_ulong_number();

        return new ASTNode('partitionKeyAlgorithm', $children);
    }

    public function partitionDefinitions()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->partitionDefinition();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->partitionDefinition();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('partitionDefinitions', $children);
    }

    public function partitionDefinition()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VALUES_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VALUES_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LESS_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LESS_SYMBOL);
                $children[] = $this->match(MySQLLexer::THAN_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->partitionValueItemListParen();
                } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::MAXVALUE_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::MAXVALUE_SYMBOL);
                } else {
                    throw new \Exception(
                        'Unexpected token in partitionDefinition: ' . $this->lexer->peekNextToken()->getText()
                    );
                }
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::IN_SYMBOL) {
                $children[] = $this->match(MySQLLexer::IN_SYMBOL);
                $children[] = $this->partitionValuesIn();
            } else {
                throw new \Exception('Unexpected token in partitionDefinition: ' . $this->lexer->peekNextToken()->getText());
            }
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NODEGROUP_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DATA_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL) {
            $children[] = $this->partitionOption();
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->subpartitionDefinition();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->subpartitionDefinition();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        }

        return new ASTNode('partitionDefinition', $children);
    }

    public function partitionValuesIn()
    {
        if ($this->lexer->peekNextToken(2)->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children = [];
            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->partitionValueItemListParen();
            while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
                $children[] = $this->partitionValueItemListParen();
            }
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
            return new ASTNode('partitionValuesIn', $children);
        } else {
            return $this->partitionValueItemListParen();
        }
    }

    public function partitionOption()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->textLiteral();
        } elseif (($token->getType() === MySQLLexer::DATA_SYMBOL || $token->getType() === MySQLLexer::INDEX_SYMBOL) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::DIRECTORY_SYMBOL) {
            if ($token->getType() === MySQLLexer::DATA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            }

            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->textLiteral();
        } elseif ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->engineRef();
        } elseif (($token->getType() === MySQLLexer::STORAGE_SYMBOL ||
                   $token->getType() === MySQLLexer::ENGINE_SYMBOL) &&
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::ENGINE_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL) {
                $children[] = $this->match(MySQLLexer::STORAGE_SYMBOL);
            }

            $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->engineRef();
        } elseif ($token->getType() === MySQLLexer::MAX_ROWS_SYMBOL || $token->getType() === MySQLLexer::MIN_ROWS_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->real_ulong_number();
        } elseif ($token->getType() === MySQLLexer::NODEGROUP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NODEGROUP_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->real_ulong_number();
        } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in partitionOption: ' . $token->getText());
        }

        return new ASTNode('partitionOption', $children);
    }

    public function subpartitionDefinition()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::SUBPARTITION_SYMBOL);
        $children[] = $this->textOrIdentifier();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::NODEGROUP_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::DATA_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::COMMENT_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
               $this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL) {
            $children[] = $this->partitionOption();
        }

        return new ASTNode('subpartitionDefinition', $children);
    }

    public function partitionValueItemListParen()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->partitionValueItem();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->partitionValueItem();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('partitionValueItemListParen', $children);
    }

    public function partitionValueItem()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::MAXVALUE_SYMBOL) {
            return $this->match(MySQLLexer::MAXVALUE_SYMBOL);
        } else {
            return $this->bitExpr();
        }
    }

    public function definerClause()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::DEFINER_SYMBOL);
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        $children[] = $this->user();
        return new ASTNode('definerClause', $children);
    }

    public function ifExists()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::IF_SYMBOL);
        $children[] = $this->match(MySQLLexer::EXISTS_SYMBOL);
        return new ASTNode('ifExists', $children);
    }

    public function ifNotExists()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::IF_SYMBOL);
        $children[] = $this->notRule();
        $children[] = $this->match(MySQLLexer::EXISTS_SYMBOL);

        return new ASTNode('ifNotExists', $children);
    }

    public function procedureParameter()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::IN_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IN_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::OUT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::OUT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::INOUT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INOUT_SYMBOL);
        }

        $children[] = $this->functionParameter();
        return new ASTNode('procedureParameter', $children);
    }

    public function functionParameter()
    {
        $children = [];

        $children[] = $this->parameterName();
        $children[] = $this->typeWithOptCollate();
        return new ASTNode('functionParameter', $children);
    }

    public function collate()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::COLLATE_SYMBOL);
        $children[] = $this->collationName();
        return new ASTNode('collate', $children);
    }

    public function typeWithOptCollate()
    {
        $children = [];

        $children[] = $this->dataType();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL) {
            $children[] = $this->collate();
        }
        return new ASTNode('typeWithOptCollate', $children);
    }

    public function allOrPartitionNameList()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::ALL_SYMBOL) {
            return $this->match(MySQLLexer::ALL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
                  $this->isIdentifierKeyword($token)) {
            return $this->identifierList();
        } else {
            throw new \Exception('Unexpected token in allOrPartitionNameList: ' . $token->getText());
        }
    }

    public function schemaIdentifierPair()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->schemaRef();
        $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
        $children[] = $this->schemaRef();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('schemaIdentifierPair', $children);
    }

    public function viewRefList()
    {
        $children = [];

        $children[] = $this->viewRef();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->viewRef();
        }

        return new ASTNode('viewRefList', $children);
    }

    public function updateList()
    {
        $children = [];
        $children[] = $this->updateElement();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->updateElement();
        }
        return new ASTNode('updateList', $children);
    }

    public function updateElement()
    {
        $children = [];
        $children[] = $this->columnRef();
        $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
        $token = $this->lexer->peekNextToken();

        if ($this->isExprStart($token)) {
            $children[] = $this->expr();
        } elseif ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in updateElement: ' . $token->getText());
        }

        return new ASTNode('updateElement', $children);
    }

    public function charsetClause()
    {
        $children = [];
        $children[] = $this->charset();
        $children[] = $this->charsetName();
        return new ASTNode('charsetClause', $children);
    }

    public function fieldsClause()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::FIELDS_SYMBOL);
        do {
            $children[] = $this->fieldTerm();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::TERMINATED_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::OPTIONALLY_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCLOSED_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ESCAPED_SYMBOL);
        return new ASTNode('fieldsClause', $children);
    }

    public function fieldTerm()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::TERMINATED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TERMINATED_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::OPTIONALLY_SYMBOL ||
                  $token->getType() === MySQLLexer::ENCLOSED_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPTIONALLY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::OPTIONALLY_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::ENCLOSED_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::ESCAPED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ESCAPED_SYMBOL);
            $children[] = $this->match(MySQLLexer::BY_SYMBOL);
            $children[] = $this->textString();
        } else {
            throw new \Exception('Unexpected token in fieldTerm: ' . $token->getText());
        }
        return new ASTNode('fieldTerm', $children);
    }

    public function linesClause()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::LINES_SYMBOL);
        do {
            $children[] = $this->lineTerm();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::TERMINATED_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::STARTING_SYMBOL);
        return new ASTNode('linesClause', $children);
    }

    public function lineTerm()
    {
        $children = [];
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::STARTING_SYMBOL) {
            $children[] = $this->match(MySQLLexer::STARTING_SYMBOL);
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::TERMINATED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TERMINATED_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in lineTerm: ' . $this->lexer->peekNextToken()->getText());
        }
        $children[] = $this->match(MySQLLexer::BY_SYMBOL);
        $children[] = $this->textString();

        return new ASTNode('lineTerm', $children);
    }

    public function userList()
    {
        $children = [];
        $children[] = $this->user();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->user();
        }
        return new ASTNode('userList', $children);
    }

    public function createUserList()
    {
        $children = [];
        $children[] = $this->createUserEntry();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->createUserEntry();
        }
        return new ASTNode('createUserList', $children);
    }

    public function alterUserList()
    {
        $children = [];
        $children[] = $this->alterUserEntry();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->alterUserEntry();
        }
        return new ASTNode('alterUserList', $children);
    }

    public function createUserEntry()
    {
        $children = [];
        $children[] = $this->user();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::BY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                if ($this->serverVersion < 80011 && $this->lexer->peekNextToken()->getType() === MySQLLexer::PASSWORD_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
                }
                $children[] = $this->textString();
            } elseif ($token->getType() === MySQLLexer::WITH_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->textOrIdentifier();

                $token = $this->lexer->peekNextToken();
                if ($token->getType() === MySQLLexer::AS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::AS_SYMBOL);
                    $children[] = $this->textStringHash();
                } elseif ($this->serverVersion >= 50706 && $token->getType() === MySQLLexer::BY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                    $children[] = $this->textString();
                } elseif ($this->serverVersion >= 80018 && $token->getType() === MySQLLexer::BY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                    $children[] = $this->match(MySQLLexer::RANDOM_SYMBOL);
                    $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in createUserEntry: ' . $token->getText());
                }
            } else {
                throw new \Exception('Unexpected token in createUserEntry: ' . $token->getText());
            }
        }
        return new ASTNode('createUserEntry', $children);
    }

    public function alterUserEntry()
    {
        $children = [];
        $children[] = $this->user();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIED_SYMBOL) {
            $children[] = $this->match(MySQLLexer::IDENTIFIED_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::WITH_SYMBOL) {
                $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
                $children[] = $this->textOrIdentifier();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AS_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::AS_SYMBOL);
                    $children[] = $this->textStringHash();
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                        $children[] = $this->retainCurrentPassword();
                    }
                } else {
                    $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                    $children[] = $this->textString();
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                        $children[] = $this->match(MySQLLexer::REPLACE_SYMBOL);
                        $children[] = $this->textString();
                    }
                    if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                        $children[] = $this->retainCurrentPassword();
                    }
                }
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::BY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BY_SYMBOL);
                $children[] = $this->textString();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::REPLACE_SYMBOL) {
                    $children[] = $this->replacePassword();
                }
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::RETAIN_SYMBOL) {
                    $children[] = $this->retainCurrentPassword();
                }
            } else {
                throw new \Exception('Unexpected token in alterUserEntry: ' . $this->lexer->peekNextToken()->getText());
            }
        } elseif ($this->serverVersion >= 80014 && $this->lexer->peekNextToken()->getType() === MySQLLexer::DISCARD_SYMBOL) {
            $children[] = $this->discardOldPassword();
        }
        return new ASTNode('alterUserEntry', $children);
    }

    public function indexOption()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
            $token->getType() === MySQLLexer::COMMENT_SYMBOL ||
            ($this->serverVersion >= 80000 &&
             ($token->getType() === MySQLLexer::VISIBLE_SYMBOL ||
              $token->getType() === MySQLLexer::INVISIBLE_SYMBOL))) {
            return $this->commonIndexOption();
        } elseif ($token->getType() === MySQLLexer::USING_SYMBOL || $token->getType() === MySQLLexer::TYPE_SYMBOL) {
            return $this->indexTypeClause();
        } else {
            throw new \Exception('Unexpected token in indexOption: ' . $token->getText());
        }
    }
    
    // These options are common for all index types.
    public function commonIndexOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KEY_BLOCK_SIZE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            $children[] = $this->textLiteral();
        } elseif ($this->serverVersion >= 80000 &&
                  ($token->getType() === MySQLLexer::VISIBLE_SYMBOL ||
                   $token->getType() === MySQLLexer::INVISIBLE_SYMBOL)) {
            $children[] = $this->visibility();
        } else {
            throw new \Exception('Unexpected token in commonIndexOption: ' . $token->getText());
        }
        return new ASTNode('commonIndexOption', $children);
    }
    public function precision()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->match(MySQLLexer::INT_NUMBER);
        $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
        $children[] = $this->match(MySQLLexer::INT_NUMBER);
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('precision', $children);
    }

    public function channel()
    {
        $children = [];
        if ($this->serverVersion >= 50706) {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::CHANNEL_SYMBOL);
            $children[] = $this->textStringNoLinebreak();

            return new ASTNode('channel', $children);
        }
        throw new \Exception('Unexpected token in channel: ' . $this->lexer->peekNextToken()->getText());
    }

    public function userVariable()
    {
        $children = [];
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::AT_SIGN_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->textOrIdentifier();
                break;
            case MySQLLexer::AT_TEXT_SUFFIX:
                $children[] = ASTNode::fromToken($token);
                break;
            default:
                throw new \Exception('Unexpected token in userVariable: ' . $token->getText());
        }

        return new ASTNode('userVariable', $children);
    }

    public function user()
    {
        $children = [];
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::IDENTIFIER:
            case MySQLLexer::BACK_TICK_QUOTED_ID:
            case MySQLLexer::DOUBLE_QUOTED_TEXT:
            case MySQLLexer::SINGLE_QUOTED_TEXT:
                $children[] = ASTNode::fromToken($token);
                break;
            case MySQLLexer::CURRENT_USER_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                    $children[] = $this->parentheses();
                }
                break;
            case MySQLLexer::AT_SIGN_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->textOrIdentifier();
                break;
            case MySQLLexer::AT_TEXT_SUFFIX:
                $children[] = ASTNode::fromToken($token);
                break;
            default:
                if ($this->isIdentifierKeyword($token)) {
                    $children[] = $this->identifierKeyword();
                    break;
                }
                throw new \Exception('Unexpected token in user: ' . $token->getText());
        }

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::AT_SIGN_SYMBOL) {
            $this->match(MySQLLexer::AT_SIGN_SYMBOL);
            $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::AT_SIGN_SYMBOL));
            $children[] = $this->textOrIdentifier();
        }

        return new ASTNode('user', $children);
    }

    public function parentheses()
    {
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('parentheses');
    }

    public function fulltextIndexOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
            $token->getType() === MySQLLexer::COMMENT_SYMBOL ||
            ($this->serverVersion >= 80000 &&
             ($token->getType() === MySQLLexer::VISIBLE_SYMBOL ||
              $token->getType() === MySQLLexer::INVISIBLE_SYMBOL))) {
            return $this->commonIndexOption();
        } elseif ($token->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
            $children[] = $this->match(MySQLLexer::PARSER_SYMBOL);
            $children[] = $this->identifier();
        } else {
            throw new \Exception('Unexpected token in fulltextIndexOption: ' . $token->getText());
        }

        return new ASTNode('fulltextIndexOption', $children);
    }

    public function spatialIndexOption()
    {
        return $this->commonIndexOption();
    }

    public function dataTypeDefinition()
    {
        $children = [];
        $children[] = $this->dataType();
        return new ASTNode('dataTypeDefinition', $children);
    }

    public function dataType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::TINYINT_SYMBOL ||
            $token->getType() === MySQLLexer::SMALLINT_SYMBOL ||
            $token->getType() === MySQLLexer::MEDIUMINT_SYMBOL ||
            $token->getType() === MySQLLexer::INT_SYMBOL ||
            $token->getType() === MySQLLexer::BIGINT_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::UNSIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ZEROFILL_SYMBOL) {
                $children[] = $this->fieldOptions();
            }
        } elseif ($token->getType() === MySQLLexer::REAL_SYMBOL || $token->getType() === MySQLLexer::DOUBLE_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRECISION_SYMBOL) {
                $this->match(MySQLLexer::PRECISION_SYMBOL);
                $children[] = ASTNode::fromToken($token);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->precision();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::UNSIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ZEROFILL_SYMBOL) {
                $children[] = $this->fieldOptions();
            }
        } elseif ($token->getType() === MySQLLexer::FLOAT_SYMBOL ||
                  $token->getType() === MySQLLexer::DECIMAL_SYMBOL ||
                  $token->getType() === MySQLLexer::NUMERIC_SYMBOL ||
                  $token->getType() === MySQLLexer::FIXED_SYMBOL) {
            $children[] = ASTNode::fromToken($this->lexer->getNextToken());
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->floatOptions();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::UNSIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ZEROFILL_SYMBOL) {
                $children[] = $this->fieldOptions();
            }
        } elseif ($token->getType() === MySQLLexer::BIT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BIT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
        } elseif ($token->getType() === MySQLLexer::BOOL_SYMBOL || $token->getType() === MySQLLexer::BOOLEAN_SYMBOL) {
            if ($token->getType() === MySQLLexer::BOOL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BOOL_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::BOOLEAN_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::CHAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::NCHAR_SYMBOL ||
                  $token->getType() === MySQLLexer::NATIONAL_SYMBOL) {
            $children[] = $this->nchar();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::BINARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
        } elseif (
            $token->getType() === MySQLLexer::CHAR_SYMBOL ||
            $token->getType() === MySQLLexer::VARCHAR_SYMBOL
        ) {
            if ($token->getType() === MySQLLexer::CHAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
                $children[] = $this->match(MySQLLexer::VARYING_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::VARCHAR_SYMBOL);
            }
            $children[] = $this->fieldLength();
            if($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::NATIONAL_SYMBOL ||
                  $token->getType() === MySQLLexer::NVARCHAR_SYMBOL ||
                  $token->getType() === MySQLLexer::NCHAR_SYMBOL) {
            if ($token->getType() === MySQLLexer::NATIONAL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NATIONAL_SYMBOL);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VARCHAR_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::VARCHAR_SYMBOL);
                } elseif ($this->lexer->peekNextToken()->getText() === 'CHAR VARYING') {
                    $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
                    $children[] = $this->match(MySQLLexer::VARYING_SYMBOL);
                } else {
                    throw new \Exception('Unexpected token in dataType: ' . $this->lexer->peekNextToken()->getText());
                }
            } elseif ($token->getType() === MySQLLexer::NVARCHAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NVARCHAR_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::NCHAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NCHAR_SYMBOL);
                $children[] = $this->match(MySQLLexer::VARYING_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in dataType: ' . $this->lexer->peekNextToken()->getText());
            }
            $children[] = $this->fieldLength();
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::VARBINARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::VARBINARY_SYMBOL);
            $children[] = $this->fieldLength();
        } elseif ($token->getText() === 'LONG VARBINARY') {
            $children[] = $this->match(MySQLLexer::LONG_SYMBOL);
            $children[] = $this->match(MySQLLexer::VARBINARY_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::YEAR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::YEAR_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::SIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::UNSIGNED_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ZEROFILL_SYMBOL) {
                $children[] = $this->fieldOptions();
            }
        } elseif ($token->getType() === MySQLLexer::DATE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::TIMESTAMP_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TIMESTAMP_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::DATETIME_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DATETIME_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->typeDatetimePrecision();
            }
        } elseif ($token->getType() === MySQLLexer::TINYBLOB_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TINYBLOB_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::BLOB_SYMBOL) {
            $children[] = $this->match(MySQLLexer::BLOB_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
        } elseif ($token->getType() === MySQLLexer::MEDIUMBLOB_SYMBOL ||
                  $token->getType() === MySQLLexer::LONGBLOB_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        } elseif ($token->getType() === MySQLLexer::LONG_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LONG_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::VARBINARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::VARBINARY_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getText() === 'CHAR VARYING') {
                $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
                $this->match(MySQLLexer                ::VARYING_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::VARYING_SYMBOL));
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::VARCHAR_SYMBOL) {
                $children[] = $this->match(MySQLLexer::VARCHAR_SYMBOL);
            }
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::TINYTEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TINYTEXT_SYMBOL);
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::TEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TEXT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
                $children[] = $this->fieldLength();
            }
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::MEDIUMTEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MEDIUMTEXT_SYMBOL);
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::LONGTEXT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LONGTEXT_SYMBOL);
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::ENUM_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ENUM_SYMBOL);
            $children[] = $this->stringList();
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->stringList();
            if ($this->isCharsetWithOptBinaryStart($this->lexer->peekNextToken())) {
                $children[] = $this->charsetWithOptBinary();
            }
        } elseif ($token->getType() === MySQLLexer::SERIAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SERIAL_SYMBOL);
        } elseif ($this->serverVersion >= 50708 && $token->getType() === MySQLLexer::JSON_SYMBOL) {
            $children[] = $this->match(MySQLLexer::JSON_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::GEOMETRY_SYMBOL ||
                  $token->getType() === MySQLLexer::POINT_SYMBOL ||
                  $token->getType() === MySQLLexer::LINESTRING_SYMBOL ||
                  $token->getType() === MySQLLexer::POLYGON_SYMBOL ||
                  $token->getType() === MySQLLexer::GEOMETRYCOLLECTION_SYMBOL ||
                  $token->getType() === MySQLLexer::MULTIPOINT_SYMBOL ||
                  $token->getType() === MySQLLexer::MULTILINESTRING_SYMBOL ||
                  $token->getType() === MySQLLexer::MULTIPOLYGON_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
        } else {
            throw new \Exception('Unexpected token in dataType: ' . $token->getText());
        }
        return new ASTNode('dataType', $children);
    }

    public function nchar()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::NCHAR_SYMBOL) {
            return $this->match(MySQLLexer::NCHAR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NATIONAL_SYMBOL) {
            $children = [];
            $children[] = $this->match(MySQLLexer::NATIONAL_SYMBOL);
            $children[] = $this->match(MySQLLexer::CHAR_SYMBOL);
            return new ASTNode('nchar', $children);
        } else {
            throw new \Exception('Unexpected token in nchar: ' . $token->getText());
        }
    }

    public function realType()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::REAL_SYMBOL) {
            $this->match(MySQLLexer::REAL_SYMBOL);
            $children[] = ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::DOUBLE_SYMBOL) {
            $this->match(MySQLLexer::DOUBLE_SYMBOL);
            $children[] = ASTNode::fromToken($token);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRECISION_SYMBOL) {
                $this->match(MySQLLexer::PRECISION_SYMBOL);
                $children[] = ASTNode::fromToken($this->lexer->peekNextToken());
            }
        } else {
            throw new \Exception('Unexpected token in realType: ' . $token->getText());
        }

        return new ASTNode('realType', $children);
    }

    public function fieldLength()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $token = $this->lexer->peekNextToken();

        if ($this->isReal_ulonglong_numberStart($token)) {
            $children[] = $this->real_ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::DECIMAL_NUMBER) {
            return $this->match(MySQLLexer::DECIMAL_NUMBER);
        } else {
            throw new \Exception('Unexpected token in fieldLength: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('fieldLength', $children);
    }

    public function fieldOptions()
    {
        $children = [];

        do {
            $token = $this->lexer->getNextToken();
            switch ($token->getType()) {
                case MySQLLexer::SIGNED_SYMBOL:
                case MySQLLexer::UNSIGNED_SYMBOL:
                case MySQLLexer::ZEROFILL_SYMBOL:
                    $children[] = ASTNode::fromToken($token);
                    break;
                default:
                    throw new \Exception('Unexpected token in fieldOptions: ' . $token->getText());
            }
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::SIGNED_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::UNSIGNED_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ZEROFILL_SYMBOL);
        return new ASTNode('fieldOptions', $children);
    }

    public function charsetWithOptBinary()
    {
        $children = [];

        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::ASCII_SYMBOL:
            case MySQLLexer::UNICODE_SYMBOL:
            case MySQLLexer::BYTE_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            case MySQLLexer::CHARSET_SYMBOL:
            case MySQLLexer::CHAR_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $children[] = $this->charsetName();
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::BINARY_SYMBOL);
                }
                break;
            case MySQLLexer::BINARY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $nextTokenType = $this->lexer->peekNextToken()->getType();
                if ($nextTokenType === MySQLLexer::CHARSET_SYMBOL ||
                    $nextTokenType === MySQLLexer::CHAR_SYMBOL) {
                    $children[] = $this->charset();
                    $children[] = $this->charsetName();
                }
                break;
            default:
                throw new \Exception('Unexpected token in charsetWithOptBinary: ' . $token->getText());
        }
        return new ASTNode('charsetWithOptBinary', $children);
    }

    public function ascii()
    {
        $children = [];
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::ASCII_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                    $this->match(MySQLLexer::BINARY_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BINARY_SYMBOL));
                }
                break;
            case MySQLLexer::BINARY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $this->match(MySQLLexer::ASCII_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::ASCII_SYMBOL));
                break;
            default:
                throw new \Exception('Unexpected token in ascii: ' . $token->getText());
        }

        return new ASTNode('ascii', $children);
    }

    public function unicode()
    {
        $children = [];
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::UNICODE_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::BINARY_SYMBOL) {
                    $this->match(MySQLLexer::BINARY_SYMBOL);
                    $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::BINARY_SYMBOL));
                }
                break;
            case MySQLLexer::BINARY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                $this->match(MySQLLexer::UNICODE_SYMBOL);
                $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::UNICODE_SYMBOL));
                break;
            default:
                throw new \Exception('Unexpected token in unicode: ' . $token->getText());
        }

        return new ASTNode('unicode', $children);
    }

    public function charsetName()
    {
        $token = $this->lexer->peekNextToken();
        switch ($token->getType()) {
            case MySQLLexer::IDENTIFIER:
            case MySQLLexer::BACK_TICK_QUOTED_ID:
            case MySQLLexer::DOUBLE_QUOTED_TEXT:
            case MySQLLexer::SINGLE_QUOTED_TEXT:
                return ASTNode::fromToken($this->lexer->getNextToken());
            case MySQLLexer::DEFAULT_SYMBOL:
                if ($this->serverVersion < 80011) {
                    return ASTNode::fromToken($this->lexer->getNextToken());
                }
            case MySQLLexer::BINARY_SYMBOL:
                return ASTNode::fromToken($this->lexer->getNextToken());
            default:
                if ($this->isIdentifierKeyword($token)) {
                    return $this->identifierKeyword();
                }

                throw new \Exception('Unexpected token in charsetName: ' . $token->getText());
        }
     }
 
    private function isAsciiStart($token)
    {
        return $token->getType() === MySQLLexer::ASCII_SYMBOL ||
               ($token->getType() === MySQLLexer::BINARY_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::ASCII_SYMBOL);
    }

    private function isUnicodeStart($token)
    {
        return $token->getType() === MySQLLexer::UNICODE_SYMBOL ||
               ($token->getType() === MySQLLexer::BINARY_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::UNICODE_SYMBOL);
    }

    public function wsNumCodepoints()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->real_ulong_number();
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('wsNumCodepoints', $children);
    }

    public function createTableOptions()
    {
        $children = [];
        $children[] = $this->createTableOption();
        while ($this->isCreateTableOptionStart($this->lexer->peekNextToken())) {
            if($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            }
            $children[] = $this->createTableOption();
        }
        return new ASTNode('createTableOptions', $children);
    }

    private function isCreateTableOptionStart($token)
    {
        return $token->getType() === MySQLLexer::ENGINE_SYMBOL ||
               ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::SECONDARY_ENGINE_SYMBOL) ||
               $token->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
               $token->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
               $token->getType() === MySQLLexer::AVG_ROW_LENGTH_SYMBOL ||
               $token->getType() === MySQLLexer::PASSWORD_SYMBOL ||
               $token->getType() === MySQLLexer::COMMENT_SYMBOL ||
               ($this->serverVersion >= 50708 && $token->getType() === MySQLLexer::COMPRESSION_SYMBOL) ||
               ($this->serverVersion >= 50711 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) ||
               $token->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL ||
               $token->getType() === MySQLLexer::PACK_KEYS_SYMBOL ||
               $token->getType() === MySQLLexer::STATS_AUTO_RECALC_SYMBOL ||
               $token->getType() === MySQLLexer::STATS_PERSISTENT_SYMBOL ||
               $token->getType() === MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL ||
               $token->getType() === MySQLLexer::CHECKSUM_SYMBOL ||
               $token->getType() === MySQLLexer::TABLE_CHECKSUM_SYMBOL ||
               $token->getType() === MySQLLexer::DELAY_KEY_WRITE_SYMBOL ||
               $token->getType() === MySQLLexer::ROW_FORMAT_SYMBOL ||
               $token->getType() === MySQLLexer::UNION_SYMBOL ||
               $token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
               $token->getType() === MySQLLexer::COLLATE_SYMBOL ||
               $token->getType() === MySQLLexer::CHARSET_SYMBOL ||
               $token->getType() === MySQLLexer::CHAR_SYMBOL ||
               $token->getType() === MySQLLexer::INSERT_METHOD_SYMBOL ||
               $token->getType() === MySQLLexer::DATA_SYMBOL ||
               $token->getType() === MySQLLexer::INDEX_SYMBOL ||
               $token->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
               $token->getType() === MySQLLexer::STORAGE_SYMBOL ||
               $token->getType() === MySQLLexer::CONNECTION_SYMBOL ||
               $token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL;
    }

    public function createTableOptionsSpaceSeparated()
    {
        $children = [];
        do {
            $children[] = $this->createTableOption();
        } while ($this->lexer->peekNextToken()->getType() === MySQLLexer::ENGINE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::AVG_ROW_LENGTH_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CHECKSUM_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::TABLE_CHECKSUM_SYMBOL ||
                 ($this->serverVersion >= 50708 &&
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::COMPRESSION_SYMBOL) ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CONNECTION_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DATA_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DEFAULT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATION_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CHARACTER_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::DELAY_KEY_WRITE_SYMBOL ||
                 ($this->serverVersion >= 50711 &&
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::ENCRYPTION_SYMBOL) ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::INSERT_METHOD_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MAX_ROWS_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::MIN_ROWS_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::PACK_KEYS_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::PASSWORD_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::ROW_FORMAT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::STATS_AUTO_RECALC_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::STATS_PERSISTENT_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::STORAGE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::TABLESPACE_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::UNION_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CHARSET_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::CHAR_SYMBOL ||
                 $this->lexer->peekNextToken()->getType() === MySQLLexer::COLLATE_SYMBOL ||
                 ($this->serverVersion >= 80014 &&
                  $this->lexer->peekNextToken()->getType() === MySQLLexer::SECONDARY_ENGINE_SYMBOL));
        return new ASTNode('createTableOptionsSpaceSeparated', $children);
    }

    public function createTableOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::ENGINE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ENGINE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->engineRef();
        } elseif ($this->serverVersion >= 80014 && $token->getType() === MySQLLexer::SECONDARY_ENGINE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SECONDARY_ENGINE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::ASSIGN_OPERATOR) {
                $children[] = $this->equal();
            }

            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::NULL_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NULL_SYMBOL);
            } elseif ($this->isTextOrIdentifierStart($token)) {
                $children[] = $this->textOrIdentifier();
            } else {
                throw new \Exception('Unexpected token in createTableOption: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::MAX_ROWS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MAX_ROWS_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::MIN_ROWS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::MIN_ROWS_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::AVG_ROW_LENGTH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AVG_ROW_LENGTH_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::PASSWORD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PASSWORD_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textStringLiteral();
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $this->match(MySQLLexer::EQUAL_OPERATOR);
                $children[] = new ASTNode(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textStringLiteral();
        } elseif ($this->serverVersion >= 50708 && $token->getType() === MySQLLexer::COMPRESSION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMPRESSION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textString();
        } elseif ($this->serverVersion >= 50711 && $token->getType() === MySQLLexer::ENCRYPTION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ENCRYPTION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::AUTO_INCREMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::AUTO_INCREMENT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::PACK_KEYS_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PACK_KEYS_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ternaryOption();
        } elseif ($token->getType() === MySQLLexer::STATS_AUTO_RECALC_SYMBOL ||
                  $token->getType() === MySQLLexer::STATS_PERSISTENT_SYMBOL ||
                  $token->getType() === MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ternaryOption();
        } elseif ($token->getType() === MySQLLexer::CHECKSUM_SYMBOL ||
                  $token->getType() === MySQLLexer::TABLE_CHECKSUM_SYMBOL) {
            if ($token->getType() === MySQLLexer::CHECKSUM_SYMBOL) {
                $children[] = $this->match(MySQLLexer::CHECKSUM_SYMBOL);
            } else {
                $children[] = $this->match(MySQLLexer::TABLE_CHECKSUM_SYMBOL);
            }
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::DELAY_KEY_WRITE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DELAY_KEY_WRITE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ulong_number();
        } elseif ($token->getType() === MySQLLexer::ROW_FORMAT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::ROW_FORMAT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::DEFAULT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DEFAULT_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::DYNAMIC_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DYNAMIC_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::FIXED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FIXED_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::COMPRESSED_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMPRESSED_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::REDUNDANT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::REDUNDANT_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::COMPACT_SYMBOL) {
                $children[] = $this->match(MySQLLexer::COMPACT_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in createTableOption: ' . $token->getText());
            }
        } elseif ($token->getType() === MySQLLexer::UNION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNION_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }

            $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
            $children[] = $this->tableRefList();
            $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        } elseif (
            (
                $token->getType() === MySQLLexer::DEFAULT_SYMBOL && 
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::COLLATE_SYMBOL
            ) || $token->getType() === MySQLLexer::COLLATE_SYMBOL 
        ) {
            $children[] = $this->defaultCollation();
        } elseif ((
            $token->getType() === MySQLLexer::DEFAULT_SYMBOL && (
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::CHARSET_SYMBOL ||
                  $this->lexer->peekNextToken(2)->getType() === MySQLLexer::CHAR_SYMBOL)
            ) || $token->getType() === MySQLLexer::CHARSET_SYMBOL || $token->getType() === MySQLLexer::CHAR_SYMBOL){
            $children[] = $this->defaultCharset();
        } elseif ($token->getType() === MySQLLexer::INSERT_METHOD_SYMBOL) {
            $children[] = $this->match(MySQLLexer::INSERT_METHOD_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $token = $this->lexer->peekNextToken();
            if ($token->getType() === MySQLLexer::NO_SYMBOL) {
                $children[] = $this->match(MySQLLexer::NO_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::FIRST_SYMBOL) {
                $children[] = $this->match(MySQLLexer::FIRST_SYMBOL);
            } elseif ($token->getType() === MySQLLexer::LAST_SYMBOL) {
                $children[] = $this->match(MySQLLexer::LAST_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in createTableOption: ' . $token->getText());
            }
        } elseif ($token->getText() === 'DATA DIRECTORY') {
            $children[] = $this->match(MySQLLexer::DATA_SYMBOL);
            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textString();
        } elseif ($token->getText() === 'INDEX DIRECTORY') {
            $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
            $children[] = $this->match(MySQLLexer::DIRECTORY_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::TABLESPACE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::TABLESPACE_SYMBOL);
            if ($this->serverVersion >= 50707 && $this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] =           $this->identifier();
        } elseif ($token->getText() === 'STORAGE') {
            $children[] = $this->match(MySQLLexer::STORAGE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DISK_SYMBOL) {
                $children[] = $this->match(MySQLLexer::DISK_SYMBOL);
            } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::MEMORY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::MEMORY_SYMBOL);
            } else {
                throw new \Exception('Unexpected token in createTableOption: ' . $this->lexer->peekNextToken()->getText());
            }
        } elseif ($token->getType() === MySQLLexer::CONNECTION_SYMBOL) {
            $children[] = $this->match(MySQLLexer::CONNECTION_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::KEY_BLOCK_SIZE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::KEY_BLOCK_SIZE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::EQUAL_OPERATOR) {
                $children[] = $this->match(MySQLLexer::EQUAL_OPERATOR);
            }
            $children[] = $this->ulong_number();
        } else {
            throw new \Exception('Unexpected token in createTableOption: ' . $token->getText());
        }

        return new ASTNode('createTableOption', $children);
    }

    //----------------- Object names and references ------------------------------------------------------------------------

    // For each object we have at least 2 rules here:
    // 1) The name when creating that object.
    // 2) The name when used to reference it from other rules.
    //
    // Sometimes we need additional reference rules with different form, depending on the place such a reference is used.

    // A name for a field (column/index). Can be qualified with the current schema + table (although it's not a reference).
    public function fieldIdentifier()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token)) {
            $children[] = $this->identifier();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->dotIdentifier();

                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                    $children[] = $this->dotIdentifier();
                }
            }

            return new ASTNode('fieldIdentifier', $children);
        } elseif ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->dotIdentifier();

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->dotIdentifier();
            }

            return new ASTNode('fieldIdentifier', $children);
        } else {
            throw new \Exception('Unexpected token in fieldIdentifier: ' . $token->getText());
        }
    }

    public function columnName()
    {
        if ($this->serverVersion >= 80000) {
            return $this->identifier();
        } else {
            return $this->fieldIdentifier();
        }
    }

    // A reference to a column of the object we are working on.
    public function columnInternalRef()
    {
        return $this->identifier();
    }

    // column_list (+ parentheses) + opt_derived_column_list in sql_yacc.yy
    public function columnInternalRefList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->columnInternalRef();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->columnInternalRef();
        }

        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);

        return new ASTNode('columnInternalRefList', $children);
    }

    // A field identifier that can reference any schema/table.
    public function columnRef()
    {
        return $this->fieldIdentifier();
    }

    public function insertIdentifier()
    {
        $token = $this->lexer->peekNextToken(2);
        if ($token->getType() === MySQLLexer::DOT_SYMBOL && $this->lexer->peekNextToken(3)->getType() === MySQLLexer::MULT_OPERATOR) {
            return $this->tableWild();
        } else {
            return $this->columnRef();
        }
    }

    public function indexName()
    {
        return $this->identifier();
    }

    // Always internal reference. Still all qualification variations are accepted.
    public function indexRef()
    {
        return $this->fieldIdentifier();
    }

    public function tableWild()
    {
        $children = [];
        $children[] = $this->identifier();

        $children[] = $this->match(MySQLLexer::DOT_SYMBOL);

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::IDENTIFIER ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $this->lexer->peekNextToken()->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->identifier();
            $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
        }

        $children[] = $this->match(MySQLLexer::MULT_OPERATOR);

        return new ASTNode('tableWild', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function checkConstraint()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::CHECK_SYMBOL);
        $children[] = $this->exprWithParentheses();
        return new ASTNode('checkConstraint', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function constraintKeyType()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::PRIMARY_SYMBOL) {
            $children[] = $this->match(MySQLLexer::PRIMARY_SYMBOL);
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::UNIQUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNIQUE_SYMBOL);

            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL ||
                $this->lexer->peekNextToken()->getType() === MySQLLexer::INDEX_SYMBOL) {
                if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                    $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
                } else {
                    $children[] = $this->match(MySQLLexer::INDEX_SYMBOL);
                }
            }
        } else {
            throw new \Exception('Unexpected token in constraintKeyType: ' . $token->getText());
        }

        return new ASTNode('constraintKeyType', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function deleteOption()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::RESTRICT_SYMBOL) {
            return $this->match(MySQLLexer::RESTRICT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CASCADE_SYMBOL) {
            return $this->match(MySQLLexer::CASCADE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SET_SYMBOL) {
            $children[] = $this->match(MySQLLexer::SET_SYMBOL);
            $children[] = $this->nullLiteral();
        } elseif ($token->getText() === 'NO ACTION') {
            $children[] = $this->match(MySQLLexer::NO_SYMBOL);
            $children[] = $this->match(MySQLLexer::ACTION_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in deleteOption: ' . $token->getText());
        }

        return new ASTNode('deleteOption', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function gcolAttribute()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNIQUE_SYMBOL) {
            $children[] = $this->match(MySQLLexer::UNIQUE_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::KEY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
            }
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMENT_SYMBOL);
            $children[] = $this->textString();
        } elseif ($token->getType() === MySQLLexer::NOT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NOT_SYMBOL);
            $children[] = $this->match(MySQLLexer::NULL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NULL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::NULL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PRIMARY_SYMBOL ||
                  $token->getType() === MySQLLexer::KEY_SYMBOL) {
            if ($this->lexer->peekNextToken()->getType() === MySQLLexer::PRIMARY_SYMBOL) {
                $children[] = $this->match(MySQLLexer::PRIMARY_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::KEY_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in gcolAttribute: ' . $token->getText());
        }
        return new ASTNode('gcolAttribute', $children);
    }

    //----------------------------------------------------------------------------------------------------------------------

    public function likeOrWhere()
    {
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LIKE_SYMBOL) {
            return $this->likeClause();
        } elseif ($this->lexer->peekNextToken()->getType() === MySQLLexer::WHERE_SYMBOL) {
            return $this->whereClause();
        } else {
            throw new \Exception('Unexpected token in likeOrWhere: ' . $this->lexer->peekNextToken()->getText());
        }
    }

    public function onlineOption()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::ONLINE_SYMBOL:
        case MySQLLexer::OFFLINE_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function noWriteToBinLog()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::LOCAL_SYMBOL) {
            $this->match(MySQLLexer::LOCAL_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL) {
            return $this->match(MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in noWriteToBinLog: ' . $token->getText());
        }
    }

    public function usePartition()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::PARTITION_SYMBOL);
        $children[] = $this->identifierListWithParentheses();

        return new ASTNode('usePartition', $children);
    }

    //----------------- Common basic rules ---------------------------------------------------------------------------------

    public function identifier()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isPureIdentifierStart($token)) {
            return $this->pureIdentifier();
        } elseif ($this->isIdentifierKeyword($token)) {
            return $this->identifierKeyword();
        } else {
            throw new \Exception('Unexpected token for identifier: ' . $token->getText());
        }
    }

    public function identifierList()
    {
        $children = [];

        $children[] = $this->identifier();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->identifier();
        }

        return new ASTNode('identifierList', $children);
    }

    public function identifierListWithParentheses()
    {
        $children = [];
        $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::OPEN_PAR_SYMBOL));
        $children[] = $this->identifierList();
        $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        $children[] = new ASTNode(MySQLLexer::getTokenName(MySQLLexer::CLOSE_PAR_SYMBOL));
        return new ASTNode('identifierListWithParentheses', $children);
    }

    public function qualifiedIdentifier()
    {
        $children = [];

        $children[] = $this->identifier();
        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->dotIdentifier();
        }

        return new ASTNode('qualifiedIdentifier', $children);
    }

    public function simpleIdentifier()
    {
        $children = [];
        $token = $this->lexer->peekNextToken(2);
        if ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            if ($this->lexer->peekNextToken(4)->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->identifier();
                $children[] = $this->dotIdentifier();
                $children[] = $this->dotIdentifier();
                return new ASTNode('simpleIdentifier', $children);
            } else {
                $children[] = $this->identifier();
                $children[] = $this->dotIdentifier();
                return new ASTNode('simpleIdentifier', $children);
            }
        } elseif ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::EOF) {
            $children[] = $this->dotIdentifier();
            $children[] = $this->dotIdentifier();
            return new ASTNode('simpleIdentifier', $children);
        } elseif ($this->isIdentifierStart($this->lexer->peekNextToken()) ||
                  $this->isIdentifierKeyword($this->lexer->peekNextToken())) {
            $children[] = $this->identifier();

            if ($this->serverVersion < 80000 &&
                $this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::DOT_SYMBOL) {
                $children[] = $this->dotIdentifier();
                $children[] = $this->dotIdentifier();
            }

            return new ASTNode('simpleIdentifier', $children);
        } else {
            throw new \Exception('Unexpected token in simpleIdentifier: ' . $this->lexer->peekNextToken()->getText());
        }
    }

    private function isUlong_numberStart($token)
    {
        return $this->isReal_ulong_numberStart($token) ||
               $token->getType() === MySQLLexer::DECIMAL_NUMBER ||
               $token->getType() === MySQLLexer::FLOAT_NUMBER;
    }

    public function dotIdentifier()
    {
        $children = [];
        $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
        $children[] = $this->identifier();
        return new ASTNode('dotIdentifier', $children);
    }

    public function ulong_number()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isReal_ulong_numberStart($token)) {
            return $this->real_ulong_number();
        } elseif ($token->getType() === MySQLLexer::DECIMAL_NUMBER) {
            return $this->match(MySQLLexer::DECIMAL_NUMBER);
        } elseif ($token->getType() === MySQLLexer::FLOAT_NUMBER) {
            return $this->match(MySQLLexer::FLOAT_NUMBER);
        } else {
            throw new \Exception('Unexpected token in ulong_number: ' . $token->getText());
        }
    }

    public function real_ulong_number()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::INT_NUMBER:
        case MySQLLexer::HEX_NUMBER:
        case MySQLLexer::LONG_NUMBER:
        case MySQLLexer::ULONGLONG_NUMBER:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    private function isUlonglong_numberStart($token)
    {
        return $this->isReal_ulonglong_numberStart($token) ||
               $token->getType() === MySQLLexer::DECIMAL_NUMBER ||
               $token->getType() === MySQLLexer::FLOAT_NUMBER;
    }

    public function ulonglong_number()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isReal_ulonglong_numberStart($token)) {
            return $this->real_ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::DECIMAL_NUMBER) {
            return $this->match(MySQLLexer::DECIMAL_NUMBER);
        } elseif ($token->getType() === MySQLLexer::FLOAT_NUMBER) {
            return $this->match(MySQLLexer::FLOAT_NUMBER);
        } else {
            throw new \Exception('Unexpected token in ulonglong_number: ' . $token->getText());
        }
    }

    public function real_ulonglong_number()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::INT_NUMBER) {
            return $this->match(MySQLLexer::INT_NUMBER);
        } elseif ($this->serverVersion >= 80017 && $token->getType() === MySQLLexer::HEX_NUMBER) {
            return $this->match(MySQLLexer::HEX_NUMBER);
        } elseif ($token->getType() === MySQLLexer::ULONGLONG_NUMBER) {
            return $this->match(MySQLLexer::ULONGLONG_NUMBER);
        } elseif ($token->getType() === MySQLLexer::LONG_NUMBER) {
            return $this->match(MySQLLexer::LONG_NUMBER);
        } else {
            throw new \Exception('Unexpected token in real_ulonglong_number: ' . $token->getText());
        }
    }

    public function literal()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($this->isTextLiteralStart($token)) {
            $children[] = $this->textLiteral();
        } elseif ($this->isNumLiteralStart($token)) {
            $children[] = $this->numLiteral();
        } elseif ($this->isTemporalLiteralStart($token)) {
            $children[] = $this->temporalLiteral();
        } elseif ($this->isNullLiteralStart($token)) {
            $children[] = $this->nullLiteral();
        } elseif ($this->isBoolLiteralStart($token)) {
            $children[] = $this->boolLiteral();
        } elseif ($token->getType() === MySQLLexer::UNDERSCORE_CHARSET) {
            $children[] = $this->match(MySQLLexer::UNDERSCORE_CHARSET);
            $token = $this->lexer->peekNextToken();

            if ($token->getType() === MySQLLexer::HEX_NUMBER) {
                $children[] = $this->match(MySQLLexer::HEX_NUMBER);
            } elseif ($token->getType() === MySQLLexer::BIN_NUMBER) {
                $children[] = $this->match(MySQLLexer::BIN_NUMBER);
            } else {
                throw new \Exception('Unexpected token in literal: ' . $token->getText());
            }
        } else {
            throw new \Exception('Unexpected token in literal: ' . $token->getText());
        }

        return new ASTNode('literal', $children);
    }

 
    public function columnRefOrLiteral()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::IDENTIFIER ||
            $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
            $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
            $this->isIdentifierKeyword($token) ||
            $token->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->fieldIdentifier();
        } elseif ($this->isLiteralStart($token)) {
            $children[] = $this->literal();
        } else {
            throw new \Exception(
                'Unexpected token in columnRefOrLiteral: ' . $token->getText()
            );
        }

        if ($this->serverVersion >= 50708 &&
            ($this->lexer->peekNextToken()->getType() === MySQLLexer::JSON_SEPARATOR_SYMBOL ||
             $this->lexer->peekNextToken()->getType() === MySQLLexer::JSON_UNQUOTED_SEPARATOR_SYMBOL)) {
            $children[] = $this->jsonOperator();
        }

        return new ASTNode('columnRefOrLiteral', $children);
    }

    public function signedLiteral()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];
        if ($token->getType() === MySQLLexer::PLUS_OPERATOR ||
            $token->getType() === MySQLLexer::MINUS_OPERATOR) {
            $this->match($this->lexer->peekNextToken()->getType());
            $children[] = new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            $children[] = $this->ulong_number();
        } elseif ($this->isLiteralStart($token)) {
            return $this->literal();
        } else {
            throw new \Exception('Unexpected token in signedLiteral: ' . $token->getText());
        }
        return new ASTNode('signedLiteral', $children);
    }

    public function stringList()
    {
        $children = [];

        $children[] = $this->match(MySQLLexer::OPEN_PAR_SYMBOL);
        $children[] = $this->textString();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->textString();
        }
        $children[] = $this->match(MySQLLexer::CLOSE_PAR_SYMBOL);
        return new ASTNode('stringList', $children);
    }

    // TEXT_STRING_sys + TEXT_STRING_literal + TEXT_STRING_filesystem + TEXT_STRING + TEXT_STRING_password +
    // TEXT_STRING_validated in sql_yacc.yy.
    public function textStringLiteral()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $this->match(MySQLLexer::SINGLE_QUOTED_TEXT);
            return ASTNode::fromToken($token);
        } elseif (!$this->lexer->isSqlModeActive(MySQLLexer::ANSI_QUOTES) &&
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT) {
            $this->match(MySQLLexer::DOUBLE_QUOTED_TEXT);
            return ASTNode::fromToken($token);
        } else {
            throw new \Exception('Unexpected token in textStringLiteral: ' . $token->getText());
        }
    }

    public function textString()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return $this->textStringLiteral();
        } elseif ($token->getType() === MySQLLexer::HEX_NUMBER) {
            return $this->match(MySQLLexer::HEX_NUMBER);
        } elseif ($token->getType() === MySQLLexer::BIN_NUMBER) {
            return $this->match(MySQLLexer::BIN_NUMBER);
        } else {
            throw new \Exception('Unexpected token in textString: ' . $token->getText());
        }
    }

    public function textStringHash()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return $this->textStringLiteral();
        } elseif ($this->serverVersion >= 80017 && $token->getType() === MySQLLexer::HEX_NUMBER) {
            return $this->match(MySQLLexer::HEX_NUMBER);
        } else {
            throw new \Exception('Unexpected token in textStringHash: ' . $token->getText());
        }
    }

    public function textLiteral()
    {
        $children = [];
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::UNDERSCORE_CHARSET) {
            $children[] = $this->match(MySQLLexer::UNDERSCORE_CHARSET);
            $children[] = $this->textStringLiteral();
        } elseif ($token->getType() === MySQLLexer::NCHAR_TEXT) {
            $children[] = $this->match(MySQLLexer::NCHAR_TEXT);
        } elseif ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $children[] = $this->textStringLiteral();
        } else {
            throw new \Exception('Unexpected token in textLiteral: ' . $token->getText());
        }

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            $children[] = $this->textStringLiteral();
        }

        return new ASTNode('textLiteral', $children);
    }

    // A special variant of a text string that must not contain a linebreak (TEXT_STRING_sys_nonewline in sql_yacc.yy).
    // Check validity in semantic phase.
    public function textStringNoLinebreak()
    {
        return $this->textStringLiteral();
    }

    public function textStringLiteralList()
    {
        $children = [];

        $children[] = $this->textStringLiteral();

        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->textStringLiteral();
        }

        return new ASTNode('textStringLiteralList', $children);
    }

    public function numLiteral()
    {
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::INT_NUMBER:
            case MySQLLexer::LONG_NUMBER:
            case MySQLLexer::ULONGLONG_NUMBER:
            case MySQLLexer::DECIMAL_NUMBER:
            case MySQLLexer::FLOAT_NUMBER:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in numLiteral: ' . $token->getText());
        }
    }
    public function boolLiteral()
    {
        $token = $this->lexer->getNextToken();

        switch ($token->getType()) {
            case MySQLLexer::TRUE_SYMBOL:
            case MySQLLexer::FALSE_SYMBOL:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in boolLiteral: ' . $token->getText());
        }
    }

    public function nullLiteral()
    {
        $token = $this->lexer->getNextToken();

        switch ($token->getType()) {
            case MySQLLexer::NULL_SYMBOL:
            case MySQLLexer::NULL2_SYMBOL:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in nullLiteral: ' . $token->getText());
        }
    }
    
    public function temporalLiteral()
    {
        $token = $this->lexer->getNextToken();
        switch ($token->getType()) {
            case MySQLLexer::DATE_SYMBOL:
            case MySQLLexer::TIME_SYMBOL:
            case MySQLLexer::TIMESTAMP_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            default:
                throw new \Exception('Unexpected token in temporalLiteral: ' . $token->getText());
        }

        $children[] = $this->match(MySQLLexer::SINGLE_QUOTED_TEXT);

        return new ASTNode('temporalLiteral', $children);
    }

    public function floatOptions()
    {
        if ($this->lexer->peekNextToken(3)->getType() === MySQLLexer::COMMA_SYMBOL) {
            return $this->precision();
        } else {
            return $this->fieldLength();
        }
    }

    public function standardFloatOptions()
    {
        return $this->precision();
    }

    public function textOrIdentifier()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isIdentifierStart($token) ||
            $this->isIdentifierKeyword($token)) {
            return $this->identifier();
        } elseif ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return $this->textStringLiteral();
        } else {
            throw new \Exception('Unexpected token in textOrIdentifier: ' . $token->getText());
        }
    }

    public function lValueIdentifier()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isPureIdentifierStart($token)) {
            return $this->pureIdentifier();
        } elseif ($this->isLValueKeyword($token)) {
            return $this->lValueKeyword();
        } else {
            throw new \Exception('Unexpected token for identifier: ' . $token->getText());
        }
    }

    public function roleIdentifierOrText()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isRoleIdentifierStart($token)) {
            return $this->roleIdentifier();
        } elseif ($token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return $this->textStringLiteral();
        } else {
            throw new \Exception('Unexpected token for identifier: ' . $token->getText());
        }
    }

    public function sizeNumber()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isReal_ulonglong_numberStart($token)) {
            return $this->real_ulonglong_number();
        } elseif ($token->getType() === MySQLLexer::IDENTIFIER ||
                  $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
                  $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT) {
            return $this->pureIdentifier();
        } else {
            throw new \Exception('Unexpected token in sizeNumber: ' . $token->getText());
        }
    }

    public function equal()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::EQUAL_OPERATOR) {
            $this->match(MySQLLexer::EQUAL_OPERATOR);
        } elseif ($token->getType() === MySQLLexer::ASSIGN_OPERATOR) {
            $this->match(MySQLLexer::ASSIGN_OPERATOR);
        } else {
            throw new \Exception('Unexpected token in equal: ' . $token->getText());
        }

        return new ASTNode(MySQLLexer::getTokenName($token->getType()));
    }

    public function optionType()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::GLOBAL_SYMBOL) {
            return $this->match(MySQLLexer::GLOBAL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::LOCAL_SYMBOL) {
            $this->match(MySQLLexer::LOCAL_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::SESSION_SYMBOL) {
            $this->match(MySQLLexer::SESSION_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::PERSIST_SYMBOL) {
            $this->match(MySQLLexer::PERSIST_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL) {
            $this->match(MySQLLexer::PERSIST_ONLY_SYMBOL);
            return ASTNode::fromToken($token);
        } else {
            throw new \Exception('Unexpected token in optionType: ' . $token->getText());
        }
    }

    public function flushTablesOptions()
    {
        $token = $this->lexer->peekNextToken();
        $children = [];

        if ($token->getType() === MySQLLexer::FOR_SYMBOL) {
            $children[] = $this->match(MySQLLexer::FOR_SYMBOL);
            $children[] = $this->match(MySQLLexer::EXPORT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::WITH_SYMBOL) {
            $children[] = $this->match(MySQLLexer::WITH_SYMBOL);
            $children[] = $this->match(MySQLLexer::READ_SYMBOL);
            $children[] = $this->match(MySQLLexer::LOCK_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in flushTablesOptions: ' . $token->getText());
        }

        return new ASTNode('flushTablesOptions', $children);
    }

    private function isReal_ulonglong_numberStart($token)
    {
        return $token->getType() === MySQLLexer::INT_NUMBER ||
               ($this->serverVersion >= 80017 && $token->getType() === MySQLLexer::HEX_NUMBER) ||
               $token->getType() === MySQLLexer::ULONGLONG_NUMBER ||
               $token->getType() === MySQLLexer::LONG_NUMBER;
    }

    private function isReal_ulong_numberStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::INT_NUMBER:
        case MySQLLexer::HEX_NUMBER:
        case MySQLLexer::ULONGLONG_NUMBER:
        case MySQLLexer::LONG_NUMBER:
            return true;
        default:
            return false;
    }

    }

    private function isTextLiteralStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::SINGLE_QUOTED_TEXT:
        case MySQLLexer::HEX_NUMBER:
        case MySQLLexer::BIN_NUMBER:
        case MySQLLexer::NCHAR_TEXT:
        case MySQLLexer::UNDERSCORE_CHARSET:
            return true;
        case MySQLLexer::DOUBLE_QUOTED_TEXT:
            if ($this->lexer->isSqlModeActive(MySQLLexer::ANSI_QUOTES)) {
                return true;
            }
        default:
            return false;
    }

    }

    private function isNumLiteralStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::INT_NUMBER:
        case MySQLLexer::LONG_NUMBER:
        case MySQLLexer::ULONGLONG_NUMBER:
        case MySQLLexer::DECIMAL_NUMBER:
        case MySQLLexer::FLOAT_NUMBER:
            return true;
        default:
            return false;
    }

    }

    private function isTemporalLiteralStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::DATE_SYMBOL:
        case MySQLLexer::TIME_SYMBOL:
        case MySQLLexer::TIMESTAMP_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isIdentifierStart($token)
    {
        return $this->isPureIdentifierStart($token) || $this->isIdentifierKeyword($token);
    }
    
    private function isSignedLiteralStart($token)
    {
        return $token->getType() === MySQLLexer::PLUS_OPERATOR ||
               $token->getType() === MySQLLexer::MINUS_OPERATOR ||
               $this->isLiteralStart($token);
    }

    private function isLiteralStart($token)
    {
        return $this->isTextLiteralStart($token) ||
               $this->isNumLiteralStart($token) ||
               $this->isTemporalLiteralStart($token) ||
               $this->isNullLiteralStart($token) ||

               $this->isBoolLiteralStart($token);
    }

    private function isCompOp($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::EQUAL_OPERATOR:
        case MySQLLexer::NULL_SAFE_EQUAL_OPERATOR:
        case MySQLLexer::GREATER_OR_EQUAL_OPERATOR:
        case MySQLLexer::GREATER_THAN_OPERATOR:
        case MySQLLexer::LESS_OR_EQUAL_OPERATOR:
        case MySQLLexer::LESS_THAN_OPERATOR:
        case MySQLLexer::NOT_EQUAL_OPERATOR:
            return true;
        default:
            return false;
    }

    }
    
    private function isNullLiteralStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::NULL_SYMBOL:
        case MySQLLexer::NULL2_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isBoolLiteralStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::TRUE_SYMBOL:
        case MySQLLexer::FALSE_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isBoolPriStart($token)
    {
        return $this->isSimpleExprStart($token);
    }

    private function isSimpleExprStart($token)
    {
        return $this->isVariableStart($token) ||
               $this->isColumnRefStart($token) ||
               $this->isRuntimeFunctionCallStart($token) ||
               ($this->isUnambiguousIdentifierStart($token) &&
                $this->lexer->peekNextToken(2)->getType() === MySQLLexer::OPEN_PAR_SYMBOL) ||
               $this->isLiteralStart($token) ||
               $token->getType() === MySQLLexer::PARAM_MARKER ||
               $this->isSumExprStart($token) ||
               ($this->serverVersion >= 80000 && $token->getType() === MySQLLexer::GROUPING_SYMBOL) ||
               ($this->serverVersion >= 80000 && $this->isWindowFunctionCallStart($token)) ||
               $token->getType() === MySQLLexer::PLUS_OPERATOR ||
               $token->getType() === MySQLLexer::MINUS_OPERATOR ||
               $token->getType() === MySQLLexer::BITWISE_NOT_OPERATOR ||
               $token->getType() === MySQLLexer::LOGICAL_NOT_OPERATOR ||
               $token->getType() === MySQLLexer::NOT2_SYMBOL ||
               ($this->serverVersion < 80000 && $token->getType() === MySQLLexer::ROW_SYMBOL) ||
               $token->getType() === MySQLLexer::OPEN_PAR_SYMBOL ||
               ($token->getType() === MySQLLexer::EXISTS_SYMBOL ||
                $this->isSubqueryStart($this->lexer->peekNextToken(2))) ||
               $token->getType() === MySQLLexer::OPEN_CURLY_SYMBOL ||
               $token->getType() === MySQLLexer::MATCH_SYMBOL ||
               $token->getType() === MySQLLexer::BINARY_SYMBOL ||
               $token->getType() === MySQLLexer::CAST_SYMBOL ||
               $token->getType() === MySQLLexer::CASE_SYMBOL ||
               $token->getType() === MySQLLexer::CONVERT_SYMBOL ||
               $token->getType() === MySQLLexer::DEFAULT_SYMBOL ||
               $token->getType() === MySQLLexer::VALUES_SYMBOL ||
               $token->getType() === MySQLLexer::INTERVAL_SYMBOL;
    }

    private function isVariableStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::AT_SIGN_SYMBOL:
        case MySQLLexer::AT_TEXT_SUFFIX:
        case MySQLLexer::AT_AT_SIGN_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isColumnRefStart($token)
    {
        return $this->isFieldIdentifierStart($token);
    }

    private function isSubqueryStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::SELECT_SYMBOL:
        case MySQLLexer::WITH_SYMBOL:
        case MySQLLexer::OPEN_PAR_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isSumExprStart($token)
    {
        return $token->getType() === MySQLLexer::AVG_SYMBOL ||
               $token->getType() === MySQLLexer::BIT_AND_SYMBOL ||
               $token->getType() === MySQLLexer::BIT_OR_SYMBOL ||
               $token->getType() === MySQLLexer::BIT_XOR_SYMBOL ||
               $token->getType() === MySQLLexer::COUNT_SYMBOL ||
               $token->getType() === MySQLLexer::GROUP_CONCAT_SYMBOL ||
               $this->isJsonFunctionStart($token) ||
               $token->getType() === MySQLLexer::MAX_SYMBOL ||
               $token->getType() === MySQLLexer::MIN_SYMBOL ||
               $token->getType() === MySQLLexer::STD_SYMBOL ||
               $token->getType() === MySQLLexer::SUM_SYMBOL ||
               $token->getType() === MySQLLexer::VARIANCE_SYMBOL ||
               $token->getType() === MySQLLexer::STDDEV_POP_SYMBOL ||
               $token->getType() === MySQLLexer::VAR_POP_SYMBOL ||
               $token->getType() === MySQLLexer::STDDEV_SAMP_SYMBOL ||
               $token->getType() === MySQLLexer::VAR_SAMP_SYMBOL;
    }

    private function isJsonFunctionStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::JSON_ARRAYAGG_SYMBOL:
        case MySQLLexer::JSON_OBJECTAGG_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isWindowFunctionCallStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::ROW_NUMBER_SYMBOL:
        case MySQLLexer::RANK_SYMBOL:
        case MySQLLexer::DENSE_RANK_SYMBOL:
        case MySQLLexer::CUME_DIST_SYMBOL:
        case MySQLLexer::PERCENT_RANK_SYMBOL:
        case MySQLLexer::NTILE_SYMBOL:
        case MySQLLexer::LEAD_SYMBOL:
        case MySQLLexer::LAG_SYMBOL:
        case MySQLLexer::FIRST_VALUE_SYMBOL:
        case MySQLLexer::LAST_VALUE_SYMBOL:
        case MySQLLexer::NTH_VALUE_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isFieldIdentifierStart($token)
    {
        return $token->getType() === MySQLLexer::IDENTIFIER ||
               $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
               $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
               $this->isIdentifierKeyword($token) ||
               $token->getType() === MySQLLexer::DOT_SYMBOL;
    }

    private function isPureIdentifierStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::IDENTIFIER:
        case MySQLLexer::BACK_TICK_QUOTED_ID:
        case MySQLLexer::DOUBLE_QUOTED_TEXT:
            return true;
        default:
            return false;
    }

    }

    private function isIdentifierKeyword($token)
    {
        if ($this->serverVersion < 80017) {
            return $this->isLabelKeyword($token) ||
                   $this->isRoleOrIdentifierKeyword($token) ||
                   $token->getType() === MySQLLexer::EXECUTE_SYMBOL ||
                   ($this->serverVersion >= 50709 && $token->getType() === MySQLLexer::SHUTDOWN_SYMBOL) ||
                   ($this->serverVersion >= 80011 && $token->getType() === MySQLLexer::RESTART_SYMBOL);
        } else {
            return $this->isIdentifierKeywordsUnambiguous($token) ||
                   $this->isIdentifierKeywordsAmbiguous1RolesAndLabels($token) ||
                   $this->isIdentifierKeywordsAmbiguous2Labels($token) ||
                   $this->isIdentifierKeywordsAmbiguous3Roles($token) ||
                   $this->isIdentifierKeywordsAmbiguous4SystemVariables($token);
        }
    }

    private function isLabelKeyword($token)
    {
        if ($this->serverVersion < 80017) {
            return $this->isRoleOrLabelKeyword($token) ||
                   $this->isRoleOrIdentifierKeyword($token) ||
                   $token->getType() === MySQLLexer::EVENT_SYMBOL ||
                   $token->getType() === MySQLLexer::FILE_SYMBOL ||
                   $token->getType() === MySQLLexer::NONE_SYMBOL ||
                   $token->getType() === MySQLLexer::PROCESS_SYMBOL ||
                   $token->getType() === MySQLLexer::PROXY_SYMBOL ||
                   $token->getType() === MySQLLexer::RELOAD_SYMBOL ||
                   $token->getType() === MySQLLexer::REPLICATION_SYMBOL ||
                   $token->getType() === MySQLLexer::RESOURCE_SYMBOL ||
                   $token->getType() === MySQLLexer::SUPER_SYMBOL;
        } else {
            return $this->isIdentifierKeywordsUnambiguous($token) ||
                   $this->isIdentifierKeywordsAmbiguous3Roles($token) ||
                   $this->isIdentifierKeywordsAmbiguous4SystemVariables($token);
        }
    }

    private function isRoleKeyword($token)
    {
        if ($this->serverVersion < 80017) {
            return $this->isRoleOrLabelKeyword($token) ||
                   $this->isRoleOrIdentifierKeyword($token);
        } else {
            return $this->isIdentifierKeywordsUnambiguous($token) ||
                   $this->isIdentifierKeywordsAmbiguous2Labels($token) ||
                   $this->isIdentifierKeywordsAmbiguous4SystemVariables($token);
        }
    }

    private function isLValueKeyword($token)
    {
        return $this->isIdentifierKeywordsUnambiguous($token) ||
               $this->isIdentifierKeywordsAmbiguous1RolesAndLabels($token) ||
               $this->isIdentifierKeywordsAmbiguous2Labels($token) ||
               $this->isIdentifierKeywordsAmbiguous3Roles($token);
    }

    private function isRoleOrIdentifierKeyword($token)
    {
        if ($token->getType() === MySQLLexer::IMPORT_SYMBOL) {
            return $this->serverVersion < 80000;
        }

        if ($token->getType() === MySQLLexer::SHUTDOWN_SYMBOL) {
            return $this->serverVersion < 50709;
        }

            switch ($token->getType()) {
        case MySQLLexer::ACCOUNT_SYMBOL:
        case MySQLLexer::ASCII_SYMBOL:
        case MySQLLexer::ALWAYS_SYMBOL:
        case MySQLLexer::BACKUP_SYMBOL:
        case MySQLLexer::BEGIN_SYMBOL:
        case MySQLLexer::BYTE_SYMBOL:
        case MySQLLexer::CACHE_SYMBOL:
        case MySQLLexer::CHARSET_SYMBOL:
        case MySQLLexer::CHECKSUM_SYMBOL:
        case MySQLLexer::CLONE_SYMBOL:
        case MySQLLexer::CLOSE_SYMBOL:
        case MySQLLexer::COMMENT_SYMBOL:
        case MySQLLexer::COMMIT_SYMBOL:
        case MySQLLexer::CONTAINS_SYMBOL:
        case MySQLLexer::DEALLOCATE_SYMBOL:
        case MySQLLexer::DO_SYMBOL:
        case MySQLLexer::END_SYMBOL:
        case MySQLLexer::FLUSH_SYMBOL:
        case MySQLLexer::FOLLOWS_SYMBOL:
        case MySQLLexer::FORMAT_SYMBOL:
        case MySQLLexer::GROUP_REPLICATION_SYMBOL:
        case MySQLLexer::HANDLER_SYMBOL:
        case MySQLLexer::HELP_SYMBOL:
        case MySQLLexer::HOST_SYMBOL:
        case MySQLLexer::INSTALL_SYMBOL:
        case MySQLLexer::INVISIBLE_SYMBOL:
        case MySQLLexer::LANGUAGE_SYMBOL:
        case MySQLLexer::NO_SYMBOL:
        case MySQLLexer::OPEN_SYMBOL:
        case MySQLLexer::OPTIONS_SYMBOL:
        case MySQLLexer::OWNER_SYMBOL:
        case MySQLLexer::PARSER_SYMBOL:
        case MySQLLexer::PARTITION_SYMBOL:
        case MySQLLexer::PORT_SYMBOL:
        case MySQLLexer::PRECEDES_SYMBOL:
        case MySQLLexer::PREPARE_SYMBOL:
        case MySQLLexer::REMOVE_SYMBOL:
        case MySQLLexer::REPAIR_SYMBOL:
        case MySQLLexer::RESET_SYMBOL:
        case MySQLLexer::RESTORE_SYMBOL:
        case MySQLLexer::ROLE_SYMBOL:
        case MySQLLexer::ROLLBACK_SYMBOL:
        case MySQLLexer::SAVEPOINT_SYMBOL:
        case MySQLLexer::SECONDARY_SYMBOL:
        case MySQLLexer::SECONDARY_ENGINE_SYMBOL:
        case MySQLLexer::SECONDARY_LOAD_SYMBOL:
        case MySQLLexer::SECONDARY_UNLOAD_SYMBOL:
        case MySQLLexer::SECURITY_SYMBOL:
        case MySQLLexer::SERVER_SYMBOL:
        case MySQLLexer::SIGNED_SYMBOL:
        case MySQLLexer::SLAVE_SYMBOL:
        case MySQLLexer::SOCKET_SYMBOL:
        case MySQLLexer::SONAME_SYMBOL:
        case MySQLLexer::START_SYMBOL:
        case MySQLLexer::STOP_SYMBOL:
        case MySQLLexer::TRUNCATE_SYMBOL:
        case MySQLLexer::UNICODE_SYMBOL:
        case MySQLLexer::UNINSTALL_SYMBOL:
        case MySQLLexer::UPGRADE_SYMBOL:
        case MySQLLexer::VISIBLE_SYMBOL:
        case MySQLLexer::WRAPPER_SYMBOL:
        case MySQLLexer::XA_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    public function fractionalPrecision()
    {
        return $this->match(MySQLLexer::INT_NUMBER);
    }


    private function isRoleOrLabelKeyword($token)
    {
        if ($this->serverVersion >= 80000) {
            if ($token->getType() === MySQLLexer::ADMIN_SYMBOL ||
                $token->getType() === MySQLLexer::EXCHANGE_SYMBOL ||
                $token->getType() === MySQLLexer::EXPIRE_SYMBOL ||
                $token->getType() === MySQLLexer::ONLY_SYMBOL ||
                $token->getType() === MySQLLexer::SUPER_SYMBOL ||
                $token->getType() === MySQLLexer::VALIDATION_SYMBOL ||
                $token->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                return true;
            }
        }

        if ($this->serverVersion < 80000) {
            if ($token->getType() === MySQLLexer::CUBE_SYMBOL ||
                $token->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $token->getType() === MySQLLexer::IMPORT_SYMBOL ||
                $token->getType() === MySQLLexer::ROW_SYMBOL ||
                $token->getType() === MySQLLexer::ROWS_SYMBOL) {
                return true;
            }
        }

        return $this->isIdentifierKeywordsUnambiguous($token);
    }

    public function derivedTable()
    {
        $children = [];

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::LATERAL_SYMBOL) {
            $children[] = $this->match(MySQLLexer::LATERAL_SYMBOL);
        }

        $children[] = $this->subquery();
        $children[] = $this->tableAlias();

        if ($this->serverVersion >= 80000 && $this->lexer->peekNextToken()->getType() === MySQLLexer::OPEN_PAR_SYMBOL) {
            $children[] = $this->columnInternalRefList();
        }

        return new ASTNode(
            'derivedTable',
            $children
        );
    }



    private function isIdentifierKeywordsUnambiguous($token)
    {
        switch($token->getType()) {
            case MySQLLexer::ACTION_SYMBOL:
            case MySQLLexer::ACCOUNT_SYMBOL:
            case MySQLLexer::ACTIVE_SYMBOL:
            case MySQLLexer::ADDDATE_SYMBOL:
            case MySQLLexer::ADMIN_SYMBOL:
            case MySQLLexer::AFTER_SYMBOL:
            case MySQLLexer::AGAINST_SYMBOL:
            case MySQLLexer::AGGREGATE_SYMBOL:
            case MySQLLexer::ALGORITHM_SYMBOL:
            case MySQLLexer::ALWAYS_SYMBOL:
            case MySQLLexer::ANY_SYMBOL:
            case MySQLLexer::AT_SYMBOL:
            case MySQLLexer::AUTOEXTEND_SIZE_SYMBOL:
            case MySQLLexer::AUTO_INCREMENT_SYMBOL:
            case MySQLLexer::AVG_ROW_LENGTH_SYMBOL:
            case MySQLLexer::AVG_SYMBOL:
            case MySQLLexer::BACKUP_SYMBOL:
            case MySQLLexer::BINLOG_SYMBOL:
            case MySQLLexer::BIT_SYMBOL:
            case MySQLLexer::BLOCK_SYMBOL:
            case MySQLLexer::BOOLEAN_SYMBOL:
            case MySQLLexer::BOOL_SYMBOL:
            case MySQLLexer::BTREE_SYMBOL:
            case MySQLLexer::BUCKETS_SYMBOL:
            case MySQLLexer::CASCADED_SYMBOL:
            case MySQLLexer::CATALOG_NAME_SYMBOL:
            case MySQLLexer::CHAIN_SYMBOL:
            case MySQLLexer::CHANGED_SYMBOL:
            case MySQLLexer::CHANNEL_SYMBOL:
            case MySQLLexer::CIPHER_SYMBOL:
            case MySQLLexer::CLASS_ORIGIN_SYMBOL:
            case MySQLLexer::CLIENT_SYMBOL:
            case MySQLLexer::CLOSE_SYMBOL:
            case MySQLLexer::COALESCE_SYMBOL:
            case MySQLLexer::CODE_SYMBOL:
            case MySQLLexer::COLLATION_SYMBOL:
            case MySQLLexer::COLUMNS_SYMBOL:
            case MySQLLexer::COLUMN_FORMAT_SYMBOL:
            case MySQLLexer::COLUMN_NAME_SYMBOL:
            case MySQLLexer::COMMITTED_SYMBOL:
            case MySQLLexer::COMPACT_SYMBOL:
            case MySQLLexer::COMPLETION_SYMBOL:
            case MySQLLexer::COMPONENT_SYMBOL:
            case MySQLLexer::COMPRESSED_SYMBOL:
            case MySQLLexer::COMPRESSION_SYMBOL:
            case MySQLLexer::CONCURRENT_SYMBOL:
            case MySQLLexer::CONNECTION_SYMBOL:
            case MySQLLexer::CONSISTENT_SYMBOL:
            case MySQLLexer::CONSTRAINT_CATALOG_SYMBOL:
            case MySQLLexer::CONSTRAINT_NAME_SYMBOL:
            case MySQLLexer::CONSTRAINT_SCHEMA_SYMBOL:
            case MySQLLexer::CONTEXT_SYMBOL:
            case MySQLLexer::CPU_SYMBOL:
            case MySQLLexer::CURRENT_SYMBOL: // not reserved in MySQL per WL#2111 specification
            case MySQLLexer::CURSOR_NAME_SYMBOL:
            case MySQLLexer::DATAFILE_SYMBOL:
            case MySQLLexer::DATA_SYMBOL:
            case MySQLLexer::DATETIME_SYMBOL:
            case MySQLLexer::DATE_SYMBOL:
            case MySQLLexer::DAY_SYMBOL:
            case MySQLLexer::DEFAULT_AUTH_SYMBOL:
            case MySQLLexer::DEFINER_SYMBOL:
            case MySQLLexer::DEFINITION_SYMBOL:
            case MySQLLexer::DELAY_KEY_WRITE_SYMBOL:
            case MySQLLexer::DESCRIPTION_SYMBOL:
            case MySQLLexer::DIAGNOSTICS_SYMBOL:
            case MySQLLexer::DIRECTORY_SYMBOL:
            case MySQLLexer::DISABLE_SYMBOL:
            case MySQLLexer::DISCARD_SYMBOL:
            case MySQLLexer::DISK_SYMBOL:
            case MySQLLexer::DUMPFILE_SYMBOL:
            case MySQLLexer::DUPLICATE_SYMBOL:
            case MySQLLexer::DYNAMIC_SYMBOL:
            case MySQLLexer::ENABLE_SYMBOL:
            case MySQLLexer::ENCRYPTION_SYMBOL:
            case MySQLLexer::ENDS_SYMBOL:
            case MySQLLexer::ENFORCED_SYMBOL:
            case MySQLLexer::ENGINES_SYMBOL:
            case MySQLLexer::ENGINE_SYMBOL:
            case MySQLLexer::ENUM_SYMBOL:
            case MySQLLexer::ERRORS_SYMBOL:
            case MySQLLexer::ERROR_SYMBOL:
            case MySQLLexer::ESCAPE_SYMBOL:
            case MySQLLexer::EVENTS_SYMBOL:
            case MySQLLexer::EVERY_SYMBOL:
            case MySQLLexer::EXCHANGE_SYMBOL:
            case MySQLLexer::EXCLUDE_SYMBOL:
            case MySQLLexer::EXPANSION_SYMBOL:
            case MySQLLexer::EXPIRE_SYMBOL:
            case MySQLLexer::EXPORT_SYMBOL:
            case MySQLLexer::EXTENDED_SYMBOL:
            case MySQLLexer::EXTENT_SIZE_SYMBOL:
            case MySQLLexer::FAST_SYMBOL:
            case MySQLLexer::FAULTS_SYMBOL:
            case MySQLLexer::FILE_BLOCK_SIZE_SYMBOL:
            case MySQLLexer::FILTER_SYMBOL:
            case MySQLLexer::FIRST_SYMBOL:
            case MySQLLexer::FIXED_SYMBOL:
            case MySQLLexer::FOLLOWING_SYMBOL:
            case MySQLLexer::FORMAT_SYMBOL:
            case MySQLLexer::FOUND_SYMBOL:
            case MySQLLexer::FULL_SYMBOL:
            case MySQLLexer::GENERAL_SYMBOL:
            case MySQLLexer::GEOMETRYCOLLECTION_SYMBOL:
            case MySQLLexer::GEOMETRY_SYMBOL:
            case MySQLLexer::GET_FORMAT_SYMBOL:
            case MySQLLexer::GET_MASTER_PUBLIC_KEY_SYMBOL:
            case MySQLLexer::GRANTS_SYMBOL:
            case MySQLLexer::GROUP_REPLICATION_SYMBOL:
            case MySQLLexer::HASH_SYMBOL:
            case MySQLLexer::HISTOGRAM_SYMBOL:
            case MySQLLexer::HISTORY_SYMBOL:
            case MySQLLexer::HOSTS_SYMBOL:
            case MySQLLexer::HOST_SYMBOL:
            case MySQLLexer::HOUR_SYMBOL:
            case MySQLLexer::IDENTIFIED_SYMBOL:
            case MySQLLexer::IGNORE_SERVER_IDS_SYMBOL:
            case MySQLLexer::INACTIVE_SYMBOL:
            case MySQLLexer::INDEXES_SYMBOL:
            case MySQLLexer::INITIAL_SIZE_SYMBOL:
            case MySQLLexer::INSERT_METHOD_SYMBOL:
            case MySQLLexer::INSTANCE_SYMBOL:
            case MySQLLexer::INVISIBLE_SYMBOL:
            case MySQLLexer::INVOKER_SYMBOL:
            case MySQLLexer::IO_SYMBOL:
            case MySQLLexer::IPC_SYMBOL:
            case MySQLLexer::ISOLATION_SYMBOL:
            case MySQLLexer::ISSUER_SYMBOL:
            case MySQLLexer::JSON_SYMBOL:
            case MySQLLexer::KEY_BLOCK_SIZE_SYMBOL:
            case MySQLLexer::LAST_SYMBOL:
            case MySQLLexer::LEAVES_SYMBOL:
            case MySQLLexer::LESS_SYMBOL:
            case MySQLLexer::LEVEL_SYMBOL:
            case MySQLLexer::LINESTRING_SYMBOL:
            case MySQLLexer::LIST_SYMBOL:
            case MySQLLexer::LOCKED_SYMBOL:
            case MySQLLexer::LOCKS_SYMBOL:
            case MySQLLexer::LOGFILE_SYMBOL:
            case MySQLLexer::LOGS_SYMBOL:
            case MySQLLexer::MASTER_AUTO_POSITION_SYMBOL:
            case MySQLLexer::MASTER_COMPRESSION_ALGORITHM_SYMBOL:
            case MySQLLexer::MASTER_CONNECT_RETRY_SYMBOL:
            case MySQLLexer::MASTER_DELAY_SYMBOL:
            case MySQLLexer::MASTER_HEARTBEAT_PERIOD_SYMBOL:
            case MySQLLexer::MASTER_HOST_SYMBOL:
            case MySQLLexer::NETWORK_NAMESPACE_SYMBOL:
            case MySQLLexer::MASTER_LOG_FILE_SYMBOL:
            case MySQLLexer::MASTER_LOG_POS_SYMBOL:
            case MySQLLexer::MASTER_PASSWORD_SYMBOL:
            case MySQLLexer::MASTER_PORT_SYMBOL:
            case MySQLLexer::MASTER_PUBLIC_KEY_PATH_SYMBOL:
            case MySQLLexer::MASTER_RETRY_COUNT_SYMBOL:
            case MySQLLexer::MASTER_SERVER_ID_SYMBOL:
            case MySQLLexer::MASTER_SSL_CAPATH_SYMBOL:
            case MySQLLexer::MASTER_SSL_CA_SYMBOL:
            case MySQLLexer::MASTER_SSL_CERT_SYMBOL:
            case MySQLLexer::MASTER_SSL_CIPHER_SYMBOL:
            case MySQLLexer::MASTER_SSL_CRLPATH_SYMBOL:
            case MySQLLexer::MASTER_SSL_CRL_SYMBOL:
            case MySQLLexer::MASTER_SSL_KEY_SYMBOL:
            case MySQLLexer::MASTER_SSL_SYMBOL:
            case MySQLLexer::MASTER_SYMBOL:
            case MySQLLexer::MASTER_TLS_CIPHERSUITES_SYMBOL:
            case MySQLLexer::MASTER_TLS_VERSION_SYMBOL:
            case MySQLLexer::MASTER_USER_SYMBOL:
            case MySQLLexer::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL:
            case MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_ROWS_SYMBOL:
            case MySQLLexer::MAX_SIZE_SYMBOL:
            case MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL:
            case MySQLLexer::MEDIUM_SYMBOL:
            case MySQLLexer::MEMORY_SYMBOL:
            case MySQLLexer::MERGE_SYMBOL:
            case MySQLLexer::MESSAGE_TEXT_SYMBOL:
            case MySQLLexer::MICROSECOND_SYMBOL:
            case MySQLLexer::MIGRATE_SYMBOL:
            case MySQLLexer::MINUTE_SYMBOL:
            case MySQLLexer::MIN_ROWS_SYMBOL:
            case MySQLLexer::MODE_SYMBOL:
            case MySQLLexer::MODIFY_SYMBOL:
            case MySQLLexer::MONTH_SYMBOL:
            case MySQLLexer::MULTILINESTRING_SYMBOL:
            case MySQLLexer::MULTIPOINT_SYMBOL:
            case MySQLLexer::MULTIPOLYGON_SYMBOL:
            case MySQLLexer::MUTEX_SYMBOL:
            case MySQLLexer::MYSQL_ERRNO_SYMBOL:
            case MySQLLexer::NAMES_SYMBOL:
            case MySQLLexer::NAME_SYMBOL:
            case MySQLLexer::NATIONAL_SYMBOL:
            case MySQLLexer::NCHAR_SYMBOL:
            case MySQLLexer::NDBCLUSTER_SYMBOL:
            case MySQLLexer::NESTED_SYMBOL:
            case MySQLLexer::NEVER_SYMBOL:
            case MySQLLexer::NEW_SYMBOL:
            case MySQLLexer::NEXT_SYMBOL:
            case MySQLLexer::NODEGROUP_SYMBOL:
            case MySQLLexer::NOWAIT_SYMBOL:
            case MySQLLexer::NO_WAIT_SYMBOL:
            case MySQLLexer::NULLS_SYMBOL:
            case MySQLLexer::NUMBER_SYMBOL:
            case MySQLLexer::NVARCHAR_SYMBOL:
            case MySQLLexer::OFFSET_SYMBOL:
            case MySQLLexer::OJ_SYMBOL:
            case MySQLLexer::OLD_SYMBOL:
            case MySQLLexer::ONE_SYMBOL:
            case MySQLLexer::ONLY_SYMBOL:
            case MySQLLexer::OPEN_SYMBOL:
            case MySQLLexer::OPTIONAL_SYMBOL:
            case MySQLLexer::OPTIONS_SYMBOL:
            case MySQLLexer::ORDINALITY_SYMBOL:
            case MySQLLexer::ORGANIZATION_SYMBOL:
            case MySQLLexer::OTHERS_SYMBOL:
            case MySQLLexer::OWNER_SYMBOL:
            case MySQLLexer::PACK_KEYS_SYMBOL:
            case MySQLLexer::PAGE_SYMBOL:
            case MySQLLexer::PARSER_SYMBOL:
            case MySQLLexer::PARTIAL_SYMBOL:
            case MySQLLexer::PARTITIONING_SYMBOL:
            case MySQLLexer::PARTITIONS_SYMBOL:
            case MySQLLexer::PASSWORD_SYMBOL:
            case MySQLLexer::PATH_SYMBOL:
            case MySQLLexer::PHASE_SYMBOL:
            case MySQLLexer::PLUGINS_SYMBOL:
            case MySQLLexer::PLUGIN_DIR_SYMBOL:
            case MySQLLexer::PLUGIN_SYMBOL:
            case MySQLLexer::POINT_SYMBOL:
            case MySQLLexer::POLYGON_SYMBOL:
            case MySQLLexer::PORT_SYMBOL:
            case MySQLLexer::PRECEDING_SYMBOL:
            case MySQLLexer::PRESERVE_SYMBOL:
            case MySQLLexer::PREV_SYMBOL:
            case MySQLLexer::PRIVILEGES_SYMBOL:
            case MySQLLexer::PRIVILEGE_CHECKS_USER_SYMBOL:
            case MySQLLexer::PROCESSLIST_SYMBOL:
            case MySQLLexer::PROFILES_SYMBOL:
            case MySQLLexer::PROFILE_SYMBOL:
            case MySQLLexer::QUARTER_SYMBOL:
            case MySQLLexer::QUERY_SYMBOL:
            case MySQLLexer::QUICK_SYMBOL:
            case MySQLLexer::READ_ONLY_SYMBOL:
            case MySQLLexer::REBUILD_SYMBOL:
            case MySQLLexer::RECOVER_SYMBOL:
            case MySQLLexer::REDO_BUFFER_SIZE_SYMBOL:
            case MySQLLexer::REDUNDANT_SYMBOL:
            case MySQLLexer::REFERENCE_SYMBOL:
            case MySQLLexer::RELAY_SYMBOL:
            case MySQLLexer::RELAYLOG_SYMBOL:
            case MySQLLexer::RELAY_LOG_FILE_SYMBOL:
            case MySQLLexer::RELAY_LOG_POS_SYMBOL:
            case MySQLLexer::RELAY_THREAD_SYMBOL:
            case MySQLLexer::REMOVE_SYMBOL:
            case MySQLLexer::REORGANIZE_SYMBOL:
            case MySQLLexer::REPEATABLE_SYMBOL:
            case MySQLLexer::REPLICATE_DO_DB_SYMBOL:
            case MySQLLexer::REPLICATE_DO_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_IGNORE_DB_SYMBOL:
            case MySQLLexer::REPLICATE_IGNORE_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_REWRITE_DB_SYMBOL:
            case MySQLLexer::REPLICATE_WILD_DO_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_WILD_IGNORE_TABLE_SYMBOL:
            case MySQLLexer::USER_RESOURCES_SYMBOL:
            case MySQLLexer::RESPECT_SYMBOL:
            case MySQLLexer::RESTORE_SYMBOL:
            case MySQLLexer::RESUME_SYMBOL:
            case MySQLLexer::RETAIN_SYMBOL:
            case MySQLLexer::RETURNED_SQLSTATE_SYMBOL:
            case MySQLLexer::RETURNS_SYMBOL:
            case MySQLLexer::REUSE_SYMBOL:
            case MySQLLexer::REVERSE_SYMBOL:
            case MySQLLexer::ROLE_SYMBOL:
            case MySQLLexer::ROLLUP_SYMBOL:
            case MySQLLexer::ROTATE_SYMBOL:
            case MySQLLexer::ROUTINE_SYMBOL:
            case MySQLLexer::ROW_COUNT_SYMBOL:
            case MySQLLexer::ROW_FORMAT_SYMBOL:
            case MySQLLexer::RTREE_SYMBOL:
            case MySQLLexer::SCHEDULE_SYMBOL:
            case MySQLLexer::SCHEMA_NAME_SYMBOL:
            case MySQLLexer::SECONDARY_ENGINE_SYMBOL:
            case MySQLLexer::SECONDARY_LOAD_SYMBOL:
            case MySQLLexer::SECONDARY_SYMBOL:
            case MySQLLexer::SECONDARY_UNLOAD_SYMBOL:
            case MySQLLexer::SECOND_SYMBOL:
            case MySQLLexer::SECURITY_SYMBOL:
            case MySQLLexer::SERIALIZABLE_SYMBOL:
            case MySQLLexer::SERIAL_SYMBOL:
            case MySQLLexer::SERVER_SYMBOL:
            case MySQLLexer::SHARE_SYMBOL:
            case MySQLLexer::SIMPLE_SYMBOL:
            case MySQLLexer::SKIP_SYMBOL:
            case MySQLLexer::SLOW_SYMBOL:
            case MySQLLexer::SNAPSHOT_SYMBOL:
            case MySQLLexer::SOCKET_SYMBOL:
            case MySQLLexer::SONAME_SYMBOL:
            case MySQLLexer::SOUNDS_SYMBOL:
            case MySQLLexer::SOURCE_SYMBOL:
            case MySQLLexer::SQL_AFTER_GTIDS_SYMBOL:
            case MySQLLexer::SQL_AFTER_MTS_GAPS_SYMBOL:
            case MySQLLexer::SQL_BEFORE_GTIDS_SYMBOL:
            case MySQLLexer::SQL_BUFFER_RESULT_SYMBOL:
            case MySQLLexer::SQL_NO_CACHE_SYMBOL:
            case MySQLLexer::SQL_THREAD_SYMBOL:
            case MySQLLexer::SRID_SYMBOL:
            case MySQLLexer::STACKED_SYMBOL:
            case MySQLLexer::STARTS_SYMBOL:
            case MySQLLexer::STATS_AUTO_RECALC_SYMBOL:
            case MySQLLexer::STATS_PERSISTENT_SYMBOL:
            case MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL:
            case MySQLLexer::STATUS_SYMBOL:
            case MySQLLexer::STORAGE_SYMBOL:
            case MySQLLexer::STRING_SYMBOL:
            case MySQLLexer::SUBCLASS_ORIGIN_SYMBOL:
            case MySQLLexer::SUBDATE_SYMBOL:
            case MySQLLexer::SUBJECT_SYMBOL:
            case MySQLLexer::SUBPARTITIONS_SYMBOL:
            case MySQLLexer::SUBPARTITION_SYMBOL:
            case MySQLLexer::SUSPEND_SYMBOL:
            case MySQLLexer::SWAPS_SYMBOL:
            case MySQLLexer::SWITCHES_SYMBOL:
            case MySQLLexer::TABLES_SYMBOL:
            case MySQLLexer::TABLESPACE_SYMBOL:
            case MySQLLexer::TABLE_CHECKSUM_SYMBOL:
            case MySQLLexer::TABLE_NAME_SYMBOL:
            case MySQLLexer::TEMPORARY_SYMBOL:
            case MySQLLexer::TEMPTABLE_SYMBOL:
            case MySQLLexer::TEXT_SYMBOL:
            case MySQLLexer::THAN_SYMBOL:
            case MySQLLexer::THREAD_PRIORITY_SYMBOL:
            case MySQLLexer::TIES_SYMBOL:
            case MySQLLexer::TIMESTAMP_ADD_SYMBOL:
            case MySQLLexer::TIMESTAMP_DIFF_SYMBOL:
            case MySQLLexer::TIMESTAMP_SYMBOL:
            case MySQLLexer::TIME_SYMBOL:
            case MySQLLexer::TRANSACTION_SYMBOL:
            case MySQLLexer::TRIGGERS_SYMBOL:
            case MySQLLexer::TYPES_SYMBOL:
            case MySQLLexer::TYPE_SYMBOL:
            case MySQLLexer::UNBOUNDED_SYMBOL:
            case MySQLLexer::UNCOMMITTED_SYMBOL:
            case MySQLLexer::UNDEFINED_SYMBOL:
            case MySQLLexer::UNDOFILE_SYMBOL:
            case MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL:
            case MySQLLexer::UNKNOWN_SYMBOL:
            case MySQLLexer::UNTIL_SYMBOL:
            case MySQLLexer::UPGRADE_SYMBOL:
            case MySQLLexer::USER_SYMBOL:
            case MySQLLexer::USE_FRM_SYMBOL:
            case MySQLLexer::VALIDATION_SYMBOL:
            case MySQLLexer::VALUE_SYMBOL:
            case MySQLLexer::VARIABLES_SYMBOL:
            case MySQLLexer::VCPU_SYMBOL:
            case MySQLLexer::VIEW_SYMBOL:
            case MySQLLexer::VISIBLE_SYMBOL:
            case MySQLLexer::WAIT_SYMBOL:
            case MySQLLexer::WARNINGS_SYMBOL:
            case MySQLLexer::WEEK_SYMBOL:
            case MySQLLexer::WEIGHT_STRING_SYMBOL:
            case MySQLLexer::WITHOUT_SYMBOL:
            case MySQLLexer::WORK_SYMBOL:
            case MySQLLexer::WRAPPER_SYMBOL:
            case MySQLLexer::X509_SYMBOL:
            case MySQLLexer::XID_SYMBOL:
            case MySQLLexer::XML_SYMBOL:
            case MySQLLexer::YEAR_SYMBOL:
            case MySQLLexer::ARRAY_SYMBOL:
            case MySQLLexer::FAILED_LOGIN_ATTEMPTS_SYMBOL:
            case MySQLLexer::MEMBER_SYMBOL:
            case MySQLLexer::OFF_SYMBOL:
            case MySQLLexer::PASSWORD_LOCK_TIME_SYMBOL:
            case MySQLLexer::RANDOM_SYMBOL:
            case MySQLLexer::REQUIRE_ROW_FORMAT_SYMBOL:
            case MySQLLexer::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL:
            case MySQLLexer::STREAM_SYMBOL:
                return true;

            default:
                return false;
        }
    }

    private function isIdentifierKeywordsAmbiguous1RolesAndLabels($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::EXECUTE_SYMBOL:
        case MySQLLexer::RESTART_SYMBOL:
        case MySQLLexer::SHUTDOWN_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isIdentifierKeywordsAmbiguous2Labels($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::ASCII_SYMBOL:
        case MySQLLexer::BEGIN_SYMBOL:
        case MySQLLexer::BYTE_SYMBOL:
        case MySQLLexer::CACHE_SYMBOL:
        case MySQLLexer::CHARSET_SYMBOL:
        case MySQLLexer::CHECKSUM_SYMBOL:
        case MySQLLexer::CLONE_SYMBOL:
        case MySQLLexer::COMMENT_SYMBOL:
        case MySQLLexer::COMMIT_SYMBOL:
        case MySQLLexer::CONTAINS_SYMBOL:
        case MySQLLexer::DEALLOCATE_SYMBOL:
        case MySQLLexer::DO_SYMBOL:
        case MySQLLexer::END_SYMBOL:
        case MySQLLexer::FLUSH_SYMBOL:
        case MySQLLexer::FOLLOWS_SYMBOL:
        case MySQLLexer::HANDLER_SYMBOL:
        case MySQLLexer::HELP_SYMBOL:
        case MySQLLexer::IMPORT_SYMBOL:
        case MySQLLexer::INSTALL_SYMBOL:
        case MySQLLexer::LANGUAGE_SYMBOL:
        case MySQLLexer::NO_SYMBOL:
        case MySQLLexer::PRECEDES_SYMBOL:
        case MySQLLexer::PREPARE_SYMBOL:
        case MySQLLexer::REPAIR_SYMBOL:
        case MySQLLexer::RESET_SYMBOL:
        case MySQLLexer::ROLLBACK_SYMBOL:
        case MySQLLexer::SAVEPOINT_SYMBOL:
        case MySQLLexer::SIGNED_SYMBOL:
        case MySQLLexer::SLAVE_SYMBOL:
        case MySQLLexer::START_SYMBOL:
        case MySQLLexer::STOP_SYMBOL:
        case MySQLLexer::TRUNCATE_SYMBOL:
        case MySQLLexer::UNICODE_SYMBOL:
        case MySQLLexer::UNINSTALL_SYMBOL:
        case MySQLLexer::XA_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isIdentifierKeywordsAmbiguous3Roles($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::EVENT_SYMBOL:
        case MySQLLexer::FILE_SYMBOL:
        case MySQLLexer::NONE_SYMBOL:
        case MySQLLexer::PROCESS_SYMBOL:
        case MySQLLexer::PROXY_SYMBOL:
        case MySQLLexer::RELOAD_SYMBOL:
        case MySQLLexer::REPLICATION_SYMBOL:
        case MySQLLexer::RESOURCE_SYMBOL:
        case MySQLLexer::SUPER_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isIdentifierKeywordsAmbiguous4SystemVariables($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::GLOBAL_SYMBOL:
        case MySQLLexer::LOCAL_SYMBOL:
        case MySQLLexer::PERSIST_SYMBOL:
        case MySQLLexer::PERSIST_ONLY_SYMBOL:
        case MySQLLexer::SESSION_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    public function identifierKeywordsAmbiguous1RolesAndLabels()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::EXECUTE_SYMBOL) {
            return $this->match(MySQLLexer::EXECUTE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::RESTART_SYMBOL) {
            return $this->match(MySQLLexer::RESTART_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SHUTDOWN_SYMBOL) {
            return $this->match(MySQLLexer::SHUTDOWN_SYMBOL);
        } else {
            throw new \Exception(
                'Unexpected token in identifierKeywordsAmbiguous1RolesAndLabels: ' . $token->getText()
            );
        }
    }

    public function identifierKeywordsAmbiguous2Labels()
    {
        $token = $this->lexer->peekNextToken();

        if ($token->getType() === MySQLLexer::ASCII_SYMBOL) {
            return $this->match(MySQLLexer::ASCII_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::BEGIN_SYMBOL) {
            return $this->match(MySQLLexer::BEGIN_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::BYTE_SYMBOL) {
            return $this->match(MySQLLexer::BYTE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CACHE_SYMBOL) {
            return $this->match(MySQLLexer::CACHE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CHARSET_SYMBOL) {
            return $this->match(MySQLLexer::CHARSET_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CHECKSUM_SYMBOL) {
            return $this->match(MySQLLexer::CHECKSUM_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CLONE_SYMBOL) {
            return $this->match(MySQLLexer::CLONE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::COMMENT_SYMBOL) {
            return $this->match(MySQLLexer::COMMENT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::COMMIT_SYMBOL) {
            return $this->match(MySQLLexer::COMMIT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::CONTAINS_SYMBOL) {
            return $this->match(MySQLLexer::CONTAINS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DEALLOCATE_SYMBOL) {
            return $this->match(MySQLLexer::DEALLOCATE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DO_SYMBOL) {
            return $this->match(MySQLLexer::DO_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::END_SYMBOL) {
            return $this->match(MySQLLexer::END_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::FLUSH_SYMBOL) {
            return $this->match(MySQLLexer::FLUSH_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::FOLLOWS_SYMBOL) {
            return $this->match(MySQLLexer::FOLLOWS_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::HANDLER_SYMBOL) {
            return $this->match(MySQLLexer::HANDLER_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::HELP_SYMBOL) {
            return $this->match(MySQLLexer::HELP_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::IMPORT_SYMBOL) {
            return $this->match(MySQLLexer::IMPORT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::INSTALL_SYMBOL) {
            return $this->match(MySQLLexer::INSTALL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::LANGUAGE_SYMBOL) {
            return $this->match(MySQLLexer::LANGUAGE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::NO_SYMBOL) {
            return $this->match(MySQLLexer::NO_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PRECEDES_SYMBOL) {
            return $this->match(MySQLLexer::PRECEDES_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::PREPARE_SYMBOL) {
            $this->match(MySQLLexer::PREPARE_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::REPAIR_SYMBOL) {
            return $this->match(MySQLLexer::REPAIR_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::RESET_SYMBOL) {
            return $this->match(MySQLLexer::RESET_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::ROLLBACK_SYMBOL) {
            return $this->match(MySQLLexer::ROLLBACK_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SAVEPOINT_SYMBOL) {
            return $this->match(MySQLLexer::SAVEPOINT_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SIGNED_SYMBOL) {
            return $this->match(MySQLLexer::SIGNED_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::SLAVE_SYMBOL) {
            return $this->match(MySQLLexer::SLAVE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::START_SYMBOL) {
            return $this->match(MySQLLexer::START_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::STOP_SYMBOL) {
            return $this->match(MySQLLexer::STOP_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::TRUNCATE_SYMBOL) {
            return $this->match(MySQLLexer::TRUNCATE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::UNICODE_SYMBOL) {
            return $this->match(MySQLLexer::UNICODE_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::UNINSTALL_SYMBOL) {
            return $this->match(MySQLLexer::UNINSTALL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::XA_SYMBOL) {
            return $this->match(MySQLLexer::XA_SYMBOL);
        } else {
            throw new \Exception('Unexpected token in identifierKeywordsAmbiguous2Labels: ' . $token->getText());
        }
    }

    public function identifierKeywordsAmbiguous3Roles()
{
    $token = $this->lexer->getNextToken();
    switch ($token->getType()) {
        case MySQLLexer::EVENT_SYMBOL:
        case MySQLLexer::FILE_SYMBOL:
        case MySQLLexer::NONE_SYMBOL:
        case MySQLLexer::PROCESS_SYMBOL:
        case MySQLLexer::PROXY_SYMBOL:
        case MySQLLexer::RELOAD_SYMBOL:
        case MySQLLexer::REPLICATION_SYMBOL:
        case MySQLLexer::RESOURCE_SYMBOL:
        case MySQLLexer::SUPER_SYMBOL:
            return ASTNode::fromToken($token);
        default:
            throw new \Exception('Unexpected token in indexType: ' . $token->getText());
    }
}

    public function identifierKeywordsAmbiguous4SystemVariables()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::GLOBAL_SYMBOL) {
            return $this->match(MySQLLexer::GLOBAL_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::LOCAL_SYMBOL) {
            $this->match(MySQLLexer::LOCAL_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::SESSION_SYMBOL) {
            $this->match(MySQLLexer::SESSION_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::PERSIST_SYMBOL) {
            $this->match(MySQLLexer::PERSIST_SYMBOL);
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::PERSIST_ONLY_SYMBOL) {
            $this->match(MySQLLexer::PERSIST_ONLY_SYMBOL);
            return ASTNode::fromToken($token);
        } else {
            throw new \Exception('Unexpected token in identifierKeywordsAmbiguous4SystemVariables: ' . $token->getText());
        }
    }

    public function roleOrLabelKeyword()
    {
        if ($this->serverVersion < 80000) {
            $token = $this->lexer->getNextToken();
            if ($token->getType() === MySQLLexer::CUBE_SYMBOL ||
                $token->getType() === MySQLLexer::FUNCTION_SYMBOL ||
                $token->getType() === MySQLLexer::IMPORT_SYMBOL ||
                $token->getType() === MySQLLexer::ROW_SYMBOL ||
                $token->getType() === MySQLLexer::ROWS_SYMBOL) {
                $this->match($this->lexer->peekNextToken()->getType());
                return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            }
        }

        if ($this->serverVersion >= 80000) {
            $token = $this->lexer->getNextToken();
            if ($token->getType() === MySQLLexer::ADMIN_SYMBOL ||
                $token->getType() === MySQLLexer::EXCHANGE_SYMBOL ||
                $token->getType() === MySQLLexer::EXPIRE_SYMBOL ||
                $token->getType() === MySQLLexer::ONLY_SYMBOL ||
                $token->getType() === MySQLLexer::SUPER_SYMBOL ||
                $token->getType() === MySQLLexer::VALIDATION_SYMBOL ||
                $token->getType() === MySQLLexer::WITHOUT_SYMBOL) {
                $this->match($this->lexer->peekNextToken()->getType());
                return new ASTNode(MySQLLexer::getTokenName($this->lexer->peekNextToken()->getType()));
            }
        }

        $token = $this->lexer->getNextToken();
        switch($token->getType()) {
            case MySQLLexer::ACTION_SYMBOL:
            case MySQLLexer::ACTIVE_SYMBOL:
            case MySQLLexer::ADDDATE_SYMBOL:
            case MySQLLexer::AFTER_SYMBOL:
            case MySQLLexer::AGAINST_SYMBOL:
            case MySQLLexer::AGGREGATE_SYMBOL:
            case MySQLLexer::ALGORITHM_SYMBOL:
            case MySQLLexer::ANALYZE_SYMBOL:
            case MySQLLexer::ANY_SYMBOL:
            case MySQLLexer::AT_SYMBOL:
            case MySQLLexer::AUTHORS_SYMBOL:
            case MySQLLexer::AUTO_INCREMENT_SYMBOL:
            case MySQLLexer::AUTOEXTEND_SIZE_SYMBOL:
            case MySQLLexer::AVG_ROW_LENGTH_SYMBOL:
            case MySQLLexer::AVG_SYMBOL:
            case MySQLLexer::BINLOG_SYMBOL:
            case MySQLLexer::BIT_SYMBOL:
            case MySQLLexer::BLOCK_SYMBOL:
            case MySQLLexer::BOOL_SYMBOL:
            case MySQLLexer::BOOLEAN_SYMBOL:
            case MySQLLexer::BTREE_SYMBOL:
            case MySQLLexer::BUCKETS_SYMBOL:
            case MySQLLexer::CASCADED_SYMBOL:
            case MySQLLexer::CATALOG_NAME_SYMBOL:
            case MySQLLexer::CHAIN_SYMBOL:
            case MySQLLexer::CHANGED_SYMBOL:
            case MySQLLexer::CHANNEL_SYMBOL:
            case MySQLLexer::CIPHER_SYMBOL:
            case MySQLLexer::CLIENT_SYMBOL:
            case MySQLLexer::CLASS_ORIGIN_SYMBOL:
            case MySQLLexer::CLOSE_SYMBOL:
            case MySQLLexer::COALESCE_SYMBOL:
            case MySQLLexer::CODE_SYMBOL:
            case MySQLLexer::COLLATION_SYMBOL:
            case MySQLLexer::COLUMN_NAME_SYMBOL:
            case MySQLLexer::COLUMN_FORMAT_SYMBOL:
            case MySQLLexer::COLUMNS_SYMBOL:
            case MySQLLexer::COMMITTED_SYMBOL:
            case MySQLLexer::COMPACT_SYMBOL:
            case MySQLLexer::COMPLETION_SYMBOL:
            case MySQLLexer::COMPONENT_SYMBOL:
            case MySQLLexer::COMPRESSED_SYMBOL:
            case MySQLLexer::COMPRESSION_SYMBOL:
            case MySQLLexer::CONCURRENT_SYMBOL:
            case MySQLLexer::CONNECTION_SYMBOL:
            case MySQLLexer::CONSISTENT_SYMBOL:
            case MySQLLexer::CONSTRAINT_CATALOG_SYMBOL:
            case MySQLLexer::CONSTRAINT_NAME_SYMBOL:
            case MySQLLexer::CONSTRAINT_SCHEMA_SYMBOL:
            case MySQLLexer::CONTEXT_SYMBOL:
            case MySQLLexer::CONTRIBUTORS_SYMBOL:
            case MySQLLexer::CPU_SYMBOL:
            case MySQLLexer::CURRENT_SYMBOL:
            case MySQLLexer::CURSOR_NAME_SYMBOL:
            case MySQLLexer::DATA_SYMBOL:
            case MySQLLexer::DATAFILE_SYMBOL:
            case MySQLLexer::DATETIME_SYMBOL:
            case MySQLLexer::DATE_SYMBOL:
            case MySQLLexer::DAY_SYMBOL:
            case MySQLLexer::DEFAULT_AUTH_SYMBOL:
            case MySQLLexer::DEFINER_SYMBOL:
            case MySQLLexer::DELAY_KEY_WRITE_SYMBOL:
            case MySQLLexer::DES_KEY_FILE_SYMBOL:
            case MySQLLexer::DESCRIPTION_SYMBOL:
            case MySQLLexer::DIAGNOSTICS_SYMBOL:
            case MySQLLexer::DIRECTORY_SYMBOL:
            case MySQLLexer::DISABLE_SYMBOL:
            case MySQLLexer::DISCARD_SYMBOL:
            case MySQLLexer::DISK_SYMBOL:
            case MySQLLexer::DUMPFILE_SYMBOL:
            case MySQLLexer::DUPLICATE_SYMBOL:
            case MySQLLexer::DYNAMIC_SYMBOL:
            case MySQLLexer::ENABLE_SYMBOL:
            case MySQLLexer::ENCRYPTION_SYMBOL:
            case MySQLLexer::ENDS_SYMBOL:
            case MySQLLexer::ENUM_SYMBOL:
            case MySQLLexer::ENGINE_SYMBOL:
            case MySQLLexer::ENGINES_SYMBOL:
            case MySQLLexer::ERROR_SYMBOL:
            case MySQLLexer::ERRORS_SYMBOL:
            case MySQLLexer::ESCAPED_SYMBOL:
            case MySQLLexer::ESCAPE_SYMBOL:
            case MySQLLexer::EVENTS_SYMBOL:
            case MySQLLexer::EVERY_SYMBOL:
            case MySQLLexer::EXCLUDE_SYMBOL:
            case MySQLLexer::EXPANSION_SYMBOL:
            case MySQLLexer::EXPORT_SYMBOL:
            case MySQLLexer::EXTENDED_SYMBOL:
            case MySQLLexer::EXTENT_SIZE_SYMBOL:
            case MySQLLexer::FAULTS_SYMBOL:
            case MySQLLexer::FAST_SYMBOL:
            case MySQLLexer::FILE_BLOCK_SIZE_SYMBOL:
            case MySQLLexer::FILTER_SYMBOL:
            case MySQLLexer::FIRST_SYMBOL:
            case MySQLLexer::FIXED_SYMBOL:
            case MySQLLexer::FOLLOWING_SYMBOL:
            case MySQLLexer::FOUND_SYMBOL:
            case MySQLLexer::FOUND_ROWS_SYMBOL:
            case MySQLLexer::FULL_SYMBOL:
            case MySQLLexer::GENERAL_SYMBOL:
            case MySQLLexer::GEOMETRY_SYMBOL:
            case MySQLLexer::GEOMETRYCOLLECTION_SYMBOL:
            case MySQLLexer::GET_FORMAT_SYMBOL:
            case MySQLLexer::GRANTS_SYMBOL:
            case MySQLLexer::GLOBAL_SYMBOL:
            case MySQLLexer::HASH_SYMBOL:
            case MySQLLexer::HISTOGRAM_SYMBOL:
            case MySQLLexer::HISTORY_SYMBOL:
            case MySQLLexer::HOSTS_SYMBOL:
            case MySQLLexer::HOUR_SYMBOL:
            case MySQLLexer::IDENTIFIED_SYMBOL:
            case MySQLLexer::IGNORE_SERVER_IDS_SYMBOL:
            case MySQLLexer::INACTIVE_SYMBOL:
            case MySQLLexer::INDEXES_SYMBOL:
            case MySQLLexer::INITIAL_SIZE_SYMBOL:
            case MySQLLexer::INSTANCE_SYMBOL:
            case MySQLLexer::INVOKER_SYMBOL:
            case MySQLLexer::IO_SYMBOL:
            case MySQLLexer::IPC_SYMBOL:
            case MySQLLexer::ISOLATION_SYMBOL:
            case MySQLLexer::ISSUER_SYMBOL:
            case MySQLLexer::INSERT_METHOD_SYMBOL:
            case MySQLLexer::JSON_SYMBOL:
            case MySQLLexer::KEY_BLOCK_SIZE_SYMBOL:
            case MySQLLexer::LAST_SYMBOL:
            case MySQLLexer::LEAVES_SYMBOL:
            case MySQLLexer::LESS_SYMBOL:
            case MySQLLexer::LEVEL_SYMBOL:
            case MySQLLexer::LINESTRING_SYMBOL:
            case MySQLLexer::LIST_SYMBOL:
            case MySQLLexer::LOCAL_SYMBOL:
            case MySQLLexer::LOCK_SYMBOL:
            case MySQLLexer::LOCKS_SYMBOL:
            case MySQLLexer::LOCKED_SYMBOL:
            case MySQLLexer::LOGFILE_SYMBOL:
            case MySQLLexer::LOGS_SYMBOL:
            case MySQLLexer::MASTER_SYMBOL:
            case MySQLLexer::MASTER_AUTO_POSITION_SYMBOL:
            case MySQLLexer::MASTER_BIND_SYMBOL:
            case MySQLLexer::MASTER_COMPRESSION_ALGORITHM_SYMBOL:
            case MySQLLexer::MASTER_CONNECT_RETRY_SYMBOL:
            case MySQLLexer::MASTER_DELAY_SYMBOL:
            case MySQLLexer::MASTER_HEARTBEAT_PERIOD_SYMBOL:
            case MySQLLexer::MASTER_HOST_SYMBOL:
            case MySQLLexer::MASTER_LOG_FILE_SYMBOL:
            case MySQLLexer::MASTER_LOG_POS_SYMBOL:
            case MySQLLexer::MASTER_PASSWORD_SYMBOL:
            case MySQLLexer::MASTER_PORT_SYMBOL:
            case MySQLLexer::MASTER_PUBLIC_KEY_PATH_SYMBOL:
            case MySQLLexer::MASTER_RETRY_COUNT_SYMBOL:
            case MySQLLexer::MASTER_SERVER_ID_SYMBOL:
            case MySQLLexer::MASTER_SSL_CAPATH_SYMBOL:
            case MySQLLexer::MASTER_SSL_CA_SYMBOL:
            case MySQLLexer::MASTER_SSL_CERT_SYMBOL:
            case MySQLLexer::MASTER_SSL_CIPHER_SYMBOL:
            case MySQLLexer::MASTER_SSL_CRL_SYMBOL:
            case MySQLLexer::MASTER_SSL_CRLPATH_SYMBOL:
            case MySQLLexer::MASTER_SSL_KEY_SYMBOL:
            case MySQLLexer::MASTER_SSL_SYMBOL:
            case MySQLLexer::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL:
            case MySQLLexer::MASTER_TLS_CIPHERSUITES_SYMBOL:
            case MySQLLexer::MASTER_TLS_VERSION_SYMBOL:
            case MySQLLexer::MASTER_USER_SYMBOL:
            case MySQLLexer::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL:
            case MySQLLexer::MATCH_SYMBOL:
            case MySQLLexer::MAX_CONNECTIONS_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_QUERIES_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_ROWS_SYMBOL:
            case MySQLLexer::MAX_SIZE_SYMBOL:
            case MySQLLexer::MAX_STATEMENT_TIME_SYMBOL:
            case MySQLLexer::MAX_UPDATES_PER_HOUR_SYMBOL:
            case MySQLLexer::MAX_USER_CONNECTIONS_SYMBOL:
            case MySQLLexer::MAXVALUE_SYMBOL:
            case MySQLLexer::MAX_SYMBOL:
            case MySQLLexer::MEDIUM_SYMBOL:
            case MySQLLexer::MEDIUMBLOB_SYMBOL:
            case MySQLLexer::MEDIUMINT_SYMBOL:
            case MySQLLexer::MEDIUMTEXT_SYMBOL:
            case MySQLLexer::MEMBER_SYMBOL:
            case MySQLLexer::MEMORY_SYMBOL:
            case MySQLLexer::MERGE_SYMBOL:
            case MySQLLexer::MESSAGE_TEXT_SYMBOL:
            case MySQLLexer::MICROSECOND_SYMBOL:
            case MySQLLexer::MIDDLEINT_SYMBOL:
            case MySQLLexer::MIGRATE_SYMBOL:
            case MySQLLexer::MINUTE_SYMBOL:
            case MySQLLexer::MIN_ROWS_SYMBOL:
            case MySQLLexer::MIN_SYMBOL:
            case MySQLLexer::MODE_SYMBOL:
            case MySQLLexer::MODIFIES_SYMBOL:
            case MySQLLexer::MODIFY_SYMBOL:
            case MySQLLexer::MOD_SYMBOL:
            case MySQLLexer::MONTH_SYMBOL:
            case MySQLLexer::MULTILINESTRING_SYMBOL:
            case MySQLLexer::MULTIPOINT_SYMBOL:
            case MySQLLexer::MULTIPOLYGON_SYMBOL:
            case MySQLLexer::MUTEX_SYMBOL:
            case MySQLLexer::MYSQL_ERRNO_SYMBOL:
            case MySQLLexer::NAME_SYMBOL:
            case MySQLLexer::NAMES_SYMBOL:
            case MySQLLexer::NATIONAL_SYMBOL:
            case MySQLLexer::NETWORK_NAMESPACE_SYMBOL:
            case MySQLLexer::NCHAR_SYMBOL:
            case MySQLLexer::NDBCLUSTER_SYMBOL:
            case MySQLLexer::NDB_SYMBOL:
            case MySQLLexer::NEG_SYMBOL:
            case MySQLLexer::NESTED_SYMBOL:
            case MySQLLexer::NEVER_SYMBOL:
            case MySQLLexer::NEW_SYMBOL:
            case MySQLLexer::NEXT_SYMBOL:
            case MySQLLexer::NODEGROUP_SYMBOL:
            case MySQLLexer::NONE_SYMBOL:
            case MySQLLexer::NONBLOCKING_SYMBOL:
            case MySQLLexer::NO_WAIT_SYMBOL:
            case MySQLLexer::NO_WRITE_TO_BINLOG_SYMBOL:
            case MySQLLexer::NULL_SYMBOL:
            case MySQLLexer::NULLS_SYMBOL:
            case MySQLLexer::NUMBER_SYMBOL:
            case MySQLLexer::NUMERIC_SYMBOL:
            case MySQLLexer::NVARCHAR_SYMBOL:
            case MySQLLexer::OFFSET_SYMBOL:
            case MySQLLexer::OLD_PASSWORD_SYMBOL:
            case MySQLLexer::OLD_SYMBOL:
            case MySQLLexer::ONE_SYMBOL:
            case MySQLLexer::OPTIONAL_SYMBOL:
            case MySQLLexer::OPTIONALLY_SYMBOL:
            case MySQLLexer::OPTION_SYMBOL:
            case MySQLLexer::OPTIONS_SYMBOL:
            case MySQLLexer::OPTIMIZE_SYMBOL:
            case MySQLLexer::OPTIMIZER_COSTS_SYMBOL:
            case MySQLLexer::ORDINALITY_SYMBOL:
            case MySQLLexer::ORGANIZATION_SYMBOL:
            case MySQLLexer::OTHERS_SYMBOL:
            case MySQLLexer::OUTER_SYMBOL:
            case MySQLLexer::OUTFILE_SYMBOL:
            case MySQLLexer::OUT_SYMBOL:
            case MySQLLexer::OWNER_SYMBOL:
            case MySQLLexer::PACK_KEYS_SYMBOL:
            case MySQLLexer::PAGE_SYMBOL:
            case MySQLLexer::PARSER_SYMBOL:
            case MySQLLexer::PARTIAL_SYMBOL:
            case MySQLLexer::PARTITIONING_SYMBOL:
            case MySQLLexer::PARTITION_SYMBOL:
            case MySQLLexer::PARTITIONS_SYMBOL:
            case MySQLLexer::PASSWORD_SYMBOL:
            
                // $this->serverVersion >= 80019
            case MySQLLexer::PASSWORD_LOCK_TIME_SYMBOL:
            case MySQLLexer::PATH_SYMBOL:
            case MySQLLexer::PERCENT_RANK_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::PERSIST_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::PERSIST_ONLY_SYMBOL:
            case MySQLLexer::PHASE_SYMBOL:
            case MySQLLexer::PLUGIN_DIR_SYMBOL:
            case MySQLLexer::PLUGINS_SYMBOL:
            case MySQLLexer::PLUGIN_SYMBOL:
            case MySQLLexer::POINT_SYMBOL:
            case MySQLLexer::POLYGON_SYMBOL:
            case MySQLLexer::PORT_SYMBOL:
            case MySQLLexer::POSITION_SYMBOL:
            case MySQLLexer::PRECEDES_SYMBOL:
            case MySQLLexer::PRECEDING_SYMBOL:
            case MySQLLexer::PRECISION_SYMBOL:
            case MySQLLexer::PREPARE_SYMBOL:
            case MySQLLexer::PRESERVE_SYMBOL:
            case MySQLLexer::PREV_SYMBOL:
            case MySQLLexer::THREAD_PRIORITY_SYMBOL:
            case MySQLLexer::PRIVILEGES_SYMBOL:
            case MySQLLexer::PROCESSLIST_SYMBOL:
            case MySQLLexer::PROFILE_SYMBOL:
            case MySQLLexer::PROFILES_SYMBOL:
            case MySQLLexer::QUARTER_SYMBOL:
            case MySQLLexer::QUERY_SYMBOL:
            case MySQLLexer::QUICK_SYMBOL:
            case MySQLLexer::READ_ONLY_SYMBOL:
            case MySQLLexer::REBUILD_SYMBOL:
            case MySQLLexer::RECOVER_SYMBOL:
            case MySQLLexer::REDOFILE_SYMBOL:
            case MySQLLexer::REDO_BUFFER_SIZE_SYMBOL:
            case MySQLLexer::REDUNDANT_SYMBOL:
            case MySQLLexer::REFERENCES_SYMBOL:
            case MySQLLexer::REFERENCE_SYMBOL:
            case MySQLLexer::RELAY_SYMBOL:
            case MySQLLexer::RELAYLOG_SYMBOL:
            case MySQLLexer::RELAY_LOG_FILE_SYMBOL:
            case MySQLLexer::RELAY_LOG_POS_SYMBOL:
            case MySQLLexer::RELAY_THREAD_SYMBOL:
            case MySQLLexer::REMOTE_SYMBOL:
            case MySQLLexer::REORGANIZE_SYMBOL:
            case MySQLLexer::REPEATABLE_SYMBOL:
            case MySQLLexer::REPLICATE_DO_DB_SYMBOL:
            case MySQLLexer::REPLICATE_IGNORE_DB_SYMBOL:
            case MySQLLexer::REPLICATE_DO_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_IGNORE_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_REWRITE_DB_SYMBOL:
            case MySQLLexer::REPLICATE_WILD_DO_TABLE_SYMBOL:
            case MySQLLexer::REPLICATE_WILD_IGNORE_TABLE_SYMBOL:
            case MySQLLexer::USER_RESOURCES_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::RESPECT_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::RESUME_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::RETAIN_SYMBOL:
            case MySQLLexer::RETURNED_SQLSTATE_SYMBOL:
            case MySQLLexer::RETURNS_SYMBOL:
            case MySQLLexer::REUSE_SYMBOL:
            case MySQLLexer::REVERSE_SYMBOL:
            case MySQLLexer::ROLLUP_SYMBOL:
            case MySQLLexer::ROTATE_SYMBOL:
            case MySQLLexer::ROUTINE_SYMBOL:
            case MySQLLexer::ROW_COUNT_SYMBOL:
            case MySQLLexer::ROW_FORMAT_SYMBOL:
            case MySQLLexer::RTREE_SYMBOL:
            case MySQLLexer::SCHEDULE_SYMBOL:
            case MySQLLexer::SCHEMA_NAME_SYMBOL:
            case MySQLLexer::SECOND_SYMBOL:
            case MySQLLexer::SECURITY_SYMBOL:
            case MySQLLexer::SERIAL_SYMBOL:
            case MySQLLexer::SERIALIZABLE_SYMBOL:
            case MySQLLexer::SESSION_SYMBOL:
            case MySQLLexer::SHARE_SYMBOL:
            case MySQLLexer::SIMPLE_SYMBOL:
            case MySQLLexer::SKIP_SYMBOL:
            case MySQLLexer::SLOW_SYMBOL:
            case MySQLLexer::SNAPSHOT_SYMBOL:
            case MySQLLexer::SOUNDS_SYMBOL:
            case MySQLLexer::SOURCE_SYMBOL:
            case MySQLLexer::SPATIAL_SYMBOL:
            case MySQLLexer::SQL_AFTER_GTIDS_SYMBOL:
            case MySQLLexer::SQL_AFTER_MTS_GAPS_SYMBOL:
            case MySQLLexer::SQL_BEFORE_GTIDS_SYMBOL:
            case MySQLLexer::SQL_BIG_RESULT_SYMBOL:
            case MySQLLexer::SQL_BUFFER_RESULT_SYMBOL:
            case MySQLLexer::SQL_CALC_FOUND_ROWS_SYMBOL:
            case MySQLLexer::SQL_CACHE_SYMBOL:
            case MySQLLexer::SQL_NO_CACHE_SYMBOL:
            case MySQLLexer::SQL_SMALL_RESULT_SYMBOL:
            case MySQLLexer::SQL_THREAD_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::SRID_SYMBOL:
            case MySQLLexer::STACKED_SYMBOL:
            case MySQLLexer::STARTS_SYMBOL:
            case MySQLLexer::STATS_AUTO_RECALC_SYMBOL:
            case MySQLLexer::STATS_PERSISTENT_SYMBOL:
            case MySQLLexer::STATS_SAMPLE_PAGES_SYMBOL:
            case MySQLLexer::STATUS_SYMBOL:
            case MySQLLexer::STD_SYMBOL:
            case MySQLLexer::STDDEV_POP_SYMBOL:
            case MySQLLexer::STDDEV_SAMP_SYMBOL:
            case MySQLLexer::STDDEV_SYMBOL:
            case MySQLLexer::STORAGE_SYMBOL:
            case MySQLLexer::STORED_SYMBOL:
            case MySQLLexer::STRAIGHT_JOIN_SYMBOL:

            // $this->serverVersion >= 80000 
            case MySQLLexer::STREAM_SYMBOL:
            case MySQLLexer::STRING_SYMBOL:
            case MySQLLexer::SUBCLASS_ORIGIN_SYMBOL:
            case MySQLLexer::SUBDATE_SYMBOL:
            case MySQLLexer::SUBJECT_SYMBOL:
            case MySQLLexer::SUBPARTITION_SYMBOL:
            case MySQLLexer::SUBPARTITIONS_SYMBOL:
            case MySQLLexer::SUBSTR_SYMBOL:
            case MySQLLexer::SUBSTRING_SYMBOL:
            case MySQLLexer::SUM_SYMBOL:
            case MySQLLexer::SUPER_SYMBOL:
            case MySQLLexer::SUSPEND_SYMBOL:
            case MySQLLexer::SWAPS_SYMBOL:
            case MySQLLexer::SWITCHES_SYMBOL:
            case MySQLLexer::SYSDATE_SYMBOL:
            case MySQLLexer::SYSTEM_SYMBOL:
            case MySQLLexer::SYSTEM_USER_SYMBOL:
            case MySQLLexer::TABLE_NAME_SYMBOL:
            case MySQLLexer::TABLE_CHECKSUM_SYMBOL:
            case MySQLLexer::TABLES_SYMBOL:
            case MySQLLexer::TABLESPACE_SYMBOL:
            case MySQLLexer::TEMPORARY_SYMBOL:
            case MySQLLexer::TEMPTABLE_SYMBOL:
            case MySQLLexer::TERMINATED_SYMBOL:
            case MySQLLexer::TEXT_SYMBOL:
            case MySQLLexer::THAN_SYMBOL:
            case MySQLLexer::TIES_SYMBOL:
            case MySQLLexer::TIME_SYMBOL:
            case MySQLLexer::TIMESTAMP_SYMBOL:
            case MySQLLexer::TIMESTAMP_ADD_SYMBOL:
            case MySQLLexer::TIMESTAMP_DIFF_SYMBOL:
            case MySQLLexer::TINYBLOB_SYMBOL:
            case MySQLLexer::TINYINT_SYMBOL:
            case MySQLLexer::TINYTEXT_SYMBOL:
            case MySQLLexer::TYPES_SYMBOL:
            case MySQLLexer::TYPE_SYMBOL:
            case MySQLLexer::UDF_RETURNS_SYMBOL:
            case MySQLLexer::UNBOUNDED_SYMBOL:
            case MySQLLexer::UNCOMMITTED_SYMBOL:
            case MySQLLexer::UNDO_BUFFER_SIZE_SYMBOL:
            case MySQLLexer::UNDOFILE_SYMBOL:
            case MySQLLexer::UNKNOWN_SYMBOL:
            case MySQLLexer::UNTIL_SYMBOL:
            case MySQLLexer::UPGRADE_SYMBOL:
            case MySQLLexer::USER_SYMBOL:
            case MySQLLexer::USE_FRM_SYMBOL:
            case MySQLLexer::VALIDATION_SYMBOL:
            case MySQLLexer::VALUE_SYMBOL:
            case MySQLLexer::VALUES_SYMBOL:
            case MySQLLexer::VARBINARY_SYMBOL:
            case MySQLLexer::VARCHAR_SYMBOL:
            case MySQLLexer::VARIABLES_SYMBOL:
            case MySQLLexer::VARIANCE_SYMBOL:
            case MySQLLexer::VARYING_SYMBOL:
            case MySQLLexer::VAR_POP_SYMBOL:
            case MySQLLexer::VAR_SAMP_SYMBOL:
            case MySQLLexer::VCPU_SYMBOL:
            case MySQLLexer::VIEW_SYMBOL:
            case MySQLLexer::VIRTUAL_SYMBOL:
            case MySQLLexer::VISIBLE_SYMBOL:
            case MySQLLexer::WAIT_SYMBOL:
            case MySQLLexer::WARNINGS_SYMBOL:
            case MySQLLexer::WEEK_SYMBOL:
            case MySQLLexer::WEIGHT_STRING_SYMBOL:
            case MySQLLexer::WHEN_SYMBOL:
            case MySQLLexer::WHERE_SYMBOL:
            case MySQLLexer::WHILE_SYMBOL:
            case MySQLLexer::WINDOW_SYMBOL:
            case MySQLLexer::WITH_SYMBOL:
            case MySQLLexer::WITHOUT_SYMBOL:
            case MySQLLexer::WORK_SYMBOL:
            case MySQLLexer::WRAPPER_SYMBOL:
            case MySQLLexer::WRITE_SYMBOL:
            case MySQLLexer::XA_SYMBOL:
            case MySQLLexer::X509_SYMBOL:
            case MySQLLexer::XID_SYMBOL:
            case MySQLLexer::XML_SYMBOL:
            case MySQLLexer::XOR_SYMBOL:
            case MySQLLexer::YEAR_SYMBOL:
            case MySQLLexer::YEAR_MONTH_SYMBOL:
            case MySQLLexer::ZEROFILL_SYMBOL:
                return ASTNode::fromToken($token);
            default:
                throw new \Exception('Unexpected token in identifierKeywordsUnambiguous: ' . $token->getText());
        }
    }

    private function isUnambiguousIdentifierStart($token)
    {
        return $this->isPureIdentifierStart($token) || $this->isIdentifierKeyword($token);
    }

    private function isExprStart($token)
    {
        return $this->isBoolPriStart($token);
    }

    private function isIntervalTimeStampStart($token)
    {
            switch ($token->getType()) {
        case MySQLLexer::MICROSECOND_SYMBOL:
        case MySQLLexer::SECOND_SYMBOL:
        case MySQLLexer::MINUTE_SYMBOL:
        case MySQLLexer::HOUR_SYMBOL:
        case MySQLLexer::DAY_SYMBOL:
        case MySQLLexer::WEEK_SYMBOL:
        case MySQLLexer::MONTH_SYMBOL:
        case MySQLLexer::QUARTER_SYMBOL:
        case MySQLLexer::YEAR_SYMBOL:
        case MySQLLexer::SQL_TSI_SECOND_SYMBOL:
        case MySQLLexer::SQL_TSI_MINUTE_SYMBOL:
        case MySQLLexer::SQL_TSI_HOUR_SYMBOL:
        case MySQLLexer::SQL_TSI_DAY_SYMBOL:
        case MySQLLexer::SQL_TSI_WEEK_SYMBOL:
        case MySQLLexer::SQL_TSI_MONTH_SYMBOL:
        case MySQLLexer::SQL_TSI_QUARTER_SYMBOL:
        case MySQLLexer::SQL_TSI_YEAR_SYMBOL:
            return true;
        default:
            return false;
    }

    }

    private function isTextOrIdentifierStart($token)
    {
        return $token->getType() === MySQLLexer::IDENTIFIER ||
               $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
               $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
               $this->isIdentifierKeyword($token) ||
               $token->getType() === MySQLLexer::SINGLE_QUOTED_TEXT;
    }

    private function isRoleIdentifierStart($token)
    {
        return $this->isPureIdentifierStart($token) || $this->isRoleKeyword($token);
    }

    public function schemaName()
    {
        return $this->identifier();
    }

    public function schemaRef()
    {
        return $this->identifier();
    }

    public function procedureName()
    {
        return $this->qualifiedIdentifier();
    }

    public function procedureRef()
    {
        return $this->qualifiedIdentifier();
    }

    public function functionName()
    {
        return $this->qualifiedIdentifier();
    }

    public function functionRef()
    {
        return $this->qualifiedIdentifier();
    }

    public function triggerName()
    {
        return $this->qualifiedIdentifier();
    }

    public function triggerRef()
    {
        return $this->qualifiedIdentifier();
    }

    public function viewName()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->dotIdentifier();
        } elseif ($this->isQualifiedIdentifierStart($token)) {
            return $this->qualifiedIdentifier();
        } else {
            throw new \Exception('Unexpected token in viewName: ' . $token->getText());
        }
    }

    public function viewRef()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->dotIdentifier();
        } elseif ($this->isQualifiedIdentifierStart($token)) {
            return $this->qualifiedIdentifier();
        } else {
            throw new \Exception('Unexpected token in viewRef: ' . $token->getText());
        }
    }

    public function tablespaceName()
    {
        return $this->identifier();
    }

    public function tablespaceRef()
    {
        return $this->identifier();
    }

    public function logfileGroupName()
    {
        return $this->identifier();
    }

    public function logfileGroupRef()
    {
        return $this->identifier();
    }

    public function eventName()
    {
        return $this->qualifiedIdentifier();
    }

    public function eventRef()
    {
        return $this->qualifiedIdentifier();
    }

    public function serverName()
    {
        return $this->textOrIdentifier();
    }

    public function serverRef()
    {
        return $this->textOrIdentifier();
    }

    public function engineRef()
    {
        return $this->textOrIdentifier();
    }

    public function tableName()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->dotIdentifier();
        } elseif ($this->isQualifiedIdentifierStart($token)) {
            return $this->qualifiedIdentifier();
        } else {
            throw new \Exception('Unexpected token in tableName: ' . $token->getText());
        }
    }

    public function filterTableRef()
    {
        $children = [];

        $children[] = $this->schemaRef();
        $children[] = $this->dotIdentifier();

        return new ASTNode('filterTableRef', $children);
    }

    public function tableRefWithWildcard()
    {
        $children = [];
        $children[] = $this->identifier();

        if ($this->lexer->peekNextToken()->getType() === MySQLLexer::DOT_SYMBOL) {
            $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
            if ($this->lexer->peekNextToken()->getType() !== MySQLLexer::MULT_OPERATOR) {
                $children[] = $this->identifier();
                $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
            }
            $children[] = $this->match(MySQLLexer::MULT_OPERATOR);
        }

        return new ASTNode('tableRefWithWildcard', $children);
    }

    public function tableRef()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DOT_SYMBOL) {
            return $this->dotIdentifier();
        } elseif ($this->isQualifiedIdentifierStart($token)) {
            return $this->qualifiedIdentifier();
        } else {
            throw new \Exception('Unexpected token in tableRef: ' . $token->getText());
        }
    }

    public function tableRefList()
    {
        $children = [];
        $children[] = $this->tableRef();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->tableRef();
        }
        return new ASTNode('tableRefList', $children);
    }

    public function tableAliasRefList()
    {
        $children = [];
        $children[] = $this->tableRefWithWildcard();
        while ($this->lexer->peekNextToken()->getType() === MySQLLexer::COMMA_SYMBOL) {
            $children[] = $this->match(MySQLLexer::COMMA_SYMBOL);
            $children[] = $this->tableRefWithWildcard();
        }
        return new ASTNode('tableAliasRefList', $children);
    }

    public function parameterName()
    {
        return $this->identifier();
    }

    public function labelIdentifier()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isPureIdentifierStart($token)) {
            return $this->pureIdentifier();
        } elseif ($this->isLabelKeyword($token)) {
            return $this->labelKeyword();
        } else {
            throw new \Exception('Unexpected token for labelIdentifier: ' . $token->getText());
        }
    }

    public function labelRef()
    {
        return $this->labelIdentifier();
    }

    public function udfName()
    {
        return $this->identifier();
    }

    public function pluginRef()
    {
        return $this->identifier();
    }

    public function componentRef()
    {
        return $this->textStringLiteral();
    }

    public function resourceGroupRef()
    {
        return $this->identifier();
    }

    public function windowName()
    {
        return $this->identifier();
    }

    public function roleIdentifier()
    {
        $token = $this->lexer->peekNextToken();
        if ($this->isPureIdentifierStart($token)) {
            return $this->pureIdentifier();
        } elseif ($this->isRoleKeyword($token)) {
            return $this->roleKeyword();
        } else {
            throw new \Exception('Unexpected token for identifier: ' . $token->getText());
        }
    }

    public function roleRef()
    {
        return $this->roleIdentifier();
    }

    public function identifierKeyword()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isIdentifierKeywordsUnambiguous($token)) {
            return $this->identifierKeywordsUnambiguous();
        } elseif ($this->isIdentifierKeywordsAmbiguous1RolesAndLabels($token)) {
            return $this->identifierKeywordsAmbiguous1RolesAndLabels();
        } elseif ($this->isIdentifierKeywordsAmbiguous2Labels($token)) {
            return $this->identifierKeywordsAmbiguous2Labels();
        } elseif ($this->isIdentifierKeywordsAmbiguous3Roles($token)) {
            return $this->identifierKeywordsAmbiguous3Roles();
        } elseif ($this->isIdentifierKeywordsAmbiguous4SystemVariables($token)) {
            return $this->identifierKeywordsAmbiguous4SystemVariables();
        } else {
            throw new \Exception('Unexpected token in identifierKeyword: ' . $token->getText());
        }
    }

    private function identifierKeywordsUnambiguous() {
        $token = $this->lexer->getNextToken();
        if(!$this->isIdentifierKeywordsUnambiguous($token)) {
            throw new \Exception('Unexpected token in identifierKeywordsUnambiguous: ' . $token->getText());
        }
        return ASTNode::fromToken($token);
    }

    public function labelKeyword()
    {
        if ($this->serverVersion < 80017) {
            if ($this->isRoleOrLabelKeyword($this->lexer->peekNextToken())) {
                return $this->roleOrLabelKeyword();
            }
            $token = $this->lexer->getNextToken();
            switch ($token->getType()) {
                case MySQLLexer::EVENT_SYMBOL:
                case MySQLLexer::FILE_SYMBOL:
                case MySQLLexer::NONE_SYMBOL:
                case MySQLLexer::PROCESS_SYMBOL:
                case MySQLLexer::PROXY_SYMBOL:
                case MySQLLexer::RELOAD_SYMBOL:
                case MySQLLexer::REPLICATION_SYMBOL:
                case MySQLLexer::RESOURCE_SYMBOL:
                case MySQLLexer::SUPER_SYMBOL:
                    return ASTNode::fromToken($token);

                default:
                    // Handle unexpected token
                    throw new \Exception('Unexpected token: ' . $this->lexer->peekNextToken()->getText());
            }
        }

        $token = $this->lexer->peekNextToken();

        if ($this->isIdentifierKeywordsUnambiguous($token)) {
            return $this->identifierKeywordsUnambiguous();
        } elseif ($this->isIdentifierKeywordsAmbiguous3Roles($token)) {
            return $this->identifierKeywordsAmbiguous3Roles();
        } elseif ($this->isIdentifierKeywordsAmbiguous4SystemVariables($token)) {
            return $this->identifierKeywordsAmbiguous4SystemVariables();
        } else {
            throw new \Exception('Unexpected token in labelKeyword: ' . $token->getText());
        }
    }

    public function roleKeyword()
    {
        if ($this->serverVersion < 80017) {
            if ($this->isRoleOrIdentifierKeyword($this->lexer->peekNextToken())) {
                return $this->lexer->getNextToken();
            } elseif ($this->isRoleOrLabelKeyword($this->lexer->peekNextToken())) {
                return $this->roleOrLabelKeyword();
            }
        }

        $token = $this->lexer->peekNextToken();

        if ($this->isIdentifierKeywordsUnambiguous($token)) {
            return $this->identifierKeywordsUnambiguous();
        } elseif ($this->isIdentifierKeywordsAmbiguous2Labels($token)) {
            return $this->identifierKeywordsAmbiguous2Labels();
        } elseif ($this->isIdentifierKeywordsAmbiguous4SystemVariables($token)) {
            return $this->identifierKeywordsAmbiguous4SystemVariables();
        } else {
            throw new \Exception('Unexpected token in roleKeyword: ' . $token->getText());
        }
    }

    public function lValueKeyword()
    {
        $token = $this->lexer->peekNextToken();

        if ($this->isIdentifierKeywordsUnambiguous($token)) {
            return $this->identifierKeywordsUnambiguous();
        } elseif ($this->isIdentifierKeywordsAmbiguous1RolesAndLabels($token)) {
            return $this->identifierKeywordsAmbiguous1RolesAndLabels();
        } elseif ($this->isIdentifierKeywordsAmbiguous2Labels($token)) {
            return $this->identifierKeywordsAmbiguous2Labels();
        } elseif ($this->isIdentifierKeywordsAmbiguous3Roles($token)) {
            return $this->identifierKeywordsAmbiguous3Roles();
        } else {
            throw new \Exception('Unexpected token in lValueKeyword: ' . $token->getText());
        }
    }

    public function pureIdentifier()
    {
        $token = $this->lexer->getNextToken();
        if ($token->getType() === MySQLLexer::IDENTIFIER) {
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID) {
            return ASTNode::fromToken($token);
        } elseif ($token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT) {
            return ASTNode::fromToken($token);
        } else {
            throw new \Exception('Unexpected token in pureIdentifier: ' . $token->getText());
        }
    }
    
    public function columnFormat()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::FIXED_SYMBOL) {
            return $this->match(MySQLLexer::FIXED_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::DYNAMIC_SYMBOL) {
            return $this->match(MySQLLexer::DYNAMIC_SYMBOL);
        } else {
            return $this->match(MySQLLexer::DEFAULT_SYMBOL);
        }
    }

    public function storageMedia()
    {
        $token = $this->lexer->peekNextToken();
        if ($token->getType() === MySQLLexer::DISK_SYMBOL) {
            return $this->match(MySQLLexer::DISK_SYMBOL);
        } elseif ($token->getType() === MySQLLexer::MEMORY_SYMBOL) {
            return $this->match(MySQLLexer::MEMORY_SYMBOL);
        } else {
            return $this->match(MySQLLexer::DEFAULT_SYMBOL);
        }
    }

    public function varIdentType()
    {
        $children = [];
        $token = $this->lexer->getNextToken();

        switch ($token->getType()) {
            case MySQLLexer::GLOBAL_SYMBOL:
            case MySQLLexer::LOCAL_SYMBOL:
            case MySQLLexer::SESSION_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            default:
                throw new \Exception('Unexpected token in varIdentType: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
        return new ASTNode('varIdentType', $children);
    }

    public function setVarIdentType()
    {
        $token = $this->lexer->getNextToken();
        $children = [];
        switch ($token->getType()) {
            case MySQLLexer::GLOBAL_SYMBOL:
            case MySQLLexer::LOCAL_SYMBOL:
            case MySQLLexer::SESSION_SYMBOL:
            case MySQLLexer::PERSIST_SYMBOL:
            case MySQLLexer::PERSIST_ONLY_SYMBOL:
                $children[] = ASTNode::fromToken($token);
                break;
            default:
                throw new \Exception('Unexpected token in setVarIdentType: ' . $token->getText());
        }
        $children[] = $this->match(MySQLLexer::DOT_SYMBOL);
        return new ASTNode('setVarIdentType', $children);
    }

    private function isQualifiedIdentifierStart($token)
    {
        return $token->getType() === MySQLLexer::IDENTIFIER ||
               $token->getType() === MySQLLexer::BACK_TICK_QUOTED_ID ||
               $token->getType() === MySQLLexer::DOUBLE_QUOTED_TEXT ||
               $this->isIdentifierKeyword($token);
    }

    private function match($expectedType)
    {
        $token = $this->lexer->getNextToken();

        if ($token->getType() === $expectedType) {
            $node = new ASTNode(MySQLLexer::getTokenName($token->getType()), $token->getText());
            return $node;
        }

        throw new \Exception(
            'Unexpected token: ' . $token->getText() .
            ', expected ' . MySQLLexer::getTokenName($expectedType)
        );
    }
}
