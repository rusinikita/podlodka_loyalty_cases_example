// Connection pool to the loyalty database.
import pg from 'pg';

// bigint (ids, counts) as number: every id of the service fits into 2^53.
pg.types.setTypeParser(pg.types.builtins.INT8, (v) => Number(v));

export type Db = pg.Pool;

export async function connect(dsn: string, poolSize: number): Promise<Db> {
  const pool = new pg.Pool({ connectionString: dsn, max: poolSize > 0 ? poolSize : undefined });
  try {
    await pool.query('SELECT 1');
  } catch (err) {
    await pool.end();
    throw new Error(`ping: ${(err as Error).message}`);
  }
  return pool;
}

export function isUniqueViolation(err: unknown): boolean {
  return err instanceof pg.DatabaseError && err.code === '23505';
}
