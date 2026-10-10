<?php
/** Append-only record of who did what. Never throws: a logging problem must not block the work. */
class Audit
{
    public static function log($ctx, $action, $entity = '', $entityId = 0, $detail = '')
    {
        try {
            Db::run(
                'INSERT INTO audit_log (business_id, member_id, action, entity, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?)',
                array(
                    isset($ctx['business_id']) ? $ctx['business_id'] : null,
                    isset($ctx['member_id']) ? $ctx['member_id'] : null,
                    substr($action, 0, 40), substr($entity, 0, 30), (int) $entityId, substr($detail, 0, 500),
                    isset($ctx['ip']) ? substr($ctx['ip'], 0, 45) : '', Db::now(),
                )
            );
        } catch (Exception $e) {
            error_log('[EtelERP] audit failed: ' . $e->getMessage());
        }
    }
}
