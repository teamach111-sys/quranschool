import AttendanceTable from '../shared/components/AttendanceTable';

export default function Presences({ routeParams = {} }) {
    return <AttendanceTable routeParams={routeParams} />;
}
