import type { ApiGoal } from '../types/api-goals-period';

export type EditGoalModalProps = {
    meta: ApiGoal;
    periodo: string;
    onClose: () => void;
    onSaved: () => void;
};
